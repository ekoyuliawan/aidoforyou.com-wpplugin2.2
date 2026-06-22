<?php
/**
 * Metadata Extraction Endpoint Controller.
 *
 * @package AIdoforyouMetadata
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AIDOFORYOU_Endpoint_Extract extends AIDOFORYOU_Metadata_Endpoint_Base {

    public function handle_request( WP_REST_Request $request ) {
        if ( ! $this->client->is_configured() ) {
            return new WP_Error( 'no_config', __( 'Metadata service is not configured.', 'aidoforyou-metadata' ), array( 'status' => 503 ) );
        }

        $identifier = $this->get_identifier( $request );
        if ( ! $identifier ) {
            return new WP_Error( 'bad_token', __( 'Invalid session token.', 'aidoforyou-metadata' ), array( 'status' => 401 ) );
        }

        if ( $rate_err = $this->check_rate_limit( $identifier ) ) return $rate_err;

        $requested_model = sanitize_text_field( $request->get_param( 'model' ) );
        $config_json = get_option( 'afy_meta_models_config', '[]' );
        $models = json_decode( $config_json, true );
        
        $selected_model_data = null;
        if ( is_array( $models ) ) {
            foreach ( $models as $m ) {
                if ( $m['id'] === $requested_model ) {
                    $selected_model_data = $m;
                    break;
                }
            }
        }

        if ( ! $selected_model_data ) {
            return new WP_Error( 'invalid_model', __( 'The selected AI model is invalid or unavailable.', 'aidoforyou-metadata' ), array( 'status' => 400 ) );
        }

        if ( ! empty( $selected_model_data['premium'] ) && get_current_user_id() === 0 ) {
            return new WP_Error( 'premium_locked', __( 'You must register/log in to use Premium models.', 'aidoforyou-metadata' ), array( 'status' => 403 ) );
        }

        $credit_cost = (int) get_option( 'afy_meta_credit_cost', 3 );
        if ( $this->credits->get( $identifier ) < $credit_cost ) {
            return new WP_Error( 'insufficient_credits', sprintf( __( 'You need at least %d credit(s).', 'aidoforyou-metadata' ), $credit_cost ), array( 'status' => 403 ) );
        }

        $files = $request->get_file_params();
        $text_input = sanitize_textarea_field( $request->get_param( 'text_input' ) );
        
        $has_image = ( ! empty( $files['image'] ) && UPLOAD_ERR_OK === (int) $files['image']['error'] );
        $has_text  = ! empty( $text_input );

        if ( ! $has_image && ! $has_text ) {
            return new WP_Error( 'empty_input', __( 'Please provide an image or text input.', 'aidoforyou-metadata' ), array( 'status' => 400 ) );
        }

        $file_path = '';
        $mime_type = '';

        if ( $has_image ) {
            $max_bytes = (int) get_option( 'afy_meta_max_mb', 30 ) * 1024 * 1024;
            if ( (int) $files['image']['size'] > $max_bytes ) {
                return new WP_Error( 'file_too_large', __( 'Image exceeds the maximum allowed file size.', 'aidoforyou-metadata' ), array( 'status' => 400 ) );
            }
            $file_path = $files['image']['tmp_name'];
            $mime_type = $files['image']['type'] ?? 'image/jpeg';
        }

        // PENERIMAAN INDEX SERVER DARI FRONTEND
        $server_index = (int) $request->get_param( 'server_index' );

        $failed_models_json = $request->get_param( 'failed_models' );
        $failed_models      = ! empty( $failed_models_json ) ? json_decode( wp_unslash( $failed_models_json ), true ) : array();
        if ( ! is_array( $failed_models ) ) $failed_models = array();
        
        $user_generation_prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$user_rules = get_option( 'afy_meta_system_prompt', '' );

		$hardcoded_json_instruction = <<<PROMPT
		output:
		  json_only: true
		  single_root_object: true
		  extra_text: false
		  extra_keys: false

		required_fields:
		  - reverse_prompt
		  - commercial_positioning
		  - commercial_elasticity
		  - media_type
		  - filename
		  - category
		  - title
		  - keywords
		  - variation_prompts

		variation_prompts:
		  array_items_require:
			- market_niche
			- rationale
			- prompt
		  count_matches_commercial_elasticity: true
		PROMPT;

		$final_system_instruction =
			"--- CONTENT RULES ---\n\n" .
			$user_rules .
			"\n\n--- STRUCTURED OUTPUT INSTRUCTIONS ---\n\n" .
			$hardcoded_json_instruction;
        
        $thinking_level = ! empty( $selected_model_data['thinking'] ) ? $selected_model_data['thinking'] : '';

        // Eksekusi API menggunakan Server (API Key) spesifik
        $ai_response = $this->client->extract_metadata( $file_path, $mime_type, $text_input, $final_system_instruction, $user_generation_prompt, $selected_model_data['id'], $thinking_level, $server_index );

        if ( $has_image ) { @unlink( $file_path ); }

        if ( is_wp_error( $ai_response ) ) {
            $err_code  = $ai_response->get_error_code(); 
            $err_data  = $ai_response->get_error_data();
            $http_code = $err_data['http_code'] ?? 0;
            $err_msg   = strtolower( $ai_response->get_error_message() );
            
            $is_busy    = ( $http_code === 503 || strpos( $err_msg, 'high demand' ) !== false || strpos( $err_msg, 'overloaded' ) !== false );
            $is_quota   = ( $http_code === 429 || strpos( $err_msg, 'quota' ) !== false || strpos( $err_msg, 'exceeded' ) !== false );
            $is_timeout = ( $err_code === 'http_request_failed' && ( strpos( $err_msg, 'curl error 28' ) !== false || strpos( $err_msg, 'timed out' ) !== false ) );

            if ( $is_busy || $is_quota || $is_timeout ) {
                
                // 1. ROTASI SERVER (API KEY) TERLEBIH DAHULU!
                $total_keys = $this->client->get_api_keys_count();
                if ( $server_index + 1 < $total_keys ) {
                    return rest_ensure_response( array(
                        'code'              => 'switch_server',
                        'next_server_index' => $server_index + 1
                    ) );
                }

                // 2. JIKA SEMUA SERVER HABIS, BARU TAWARKAN MODEL LAIN (FALLBACK MODEL)
                $failed_models[] = $selected_model_data['id'];
                $available_fallbacks = array();
                
                foreach ( $models as $m ) {
                    if ( in_array( $m['id'], $failed_models ) ) continue;
                    if ( ! empty( $m['premium'] ) && get_current_user_id() === 0 ) continue;
                    
                    $available_fallbacks[] = array(
                        'id'    => $m['id'],
                        'label' => $m['label']
                    );
                }

                if ( count( $available_fallbacks ) > 0 ) {
                    if ( $is_quota ) $reason_text = 'has exceeded its quota limit';
                    elseif ( $is_timeout ) $reason_text = 'is taking too long to respond (Server Timeout)';
                    else $reason_text = 'is currently experiencing high demand';

                    return rest_ensure_response( array(
                        'code'                => 'fallback_required',
                        'message'             => sprintf( __( 'The AI Model (%s) %s.', 'aidoforyou-metadata' ), $selected_model_data['label'], $reason_text ),
                        'available_fallbacks' => $available_fallbacks,
                        'failed_models'       => $failed_models
                    ) );
                } else {
                    return new WP_Error( 'ai_exhausted', __( 'All available AI models and servers are currently overloaded or out of quota. Please try again later.', 'aidoforyou-metadata' ), array( 'status' => 400 ) );
                }
            }

            return new WP_Error( 'ai_error', $ai_response->get_error_message(), array( 'status' => 400 ) );
        }

        $this->credits->deduct( $identifier, $credit_cost );

        return rest_ensure_response( array(
            'code'         => 0,
            'credits'      => $this->credits->get( $identifier ),
            'metadata'     => $ai_response,
            'model_label'  => $selected_model_data['label'],
            'server_label' => 'Server ' . ($server_index + 1),
            'generated_at' => current_time( 'M j, Y - H:i:s' )
        ) );
    }
}