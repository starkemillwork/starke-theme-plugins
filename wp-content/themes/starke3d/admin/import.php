<?php
/**
 * Starke Millwork Custom Importer Functions
 *
 * To use, place this file in your theme's root directory and add the following
 * line to the top of your functions.php file:
 * require_once get_template_directory() . '/import.php';
 *
 * This file requires the PhpSpreadsheet library, which should be available
 * via the autoloader in your WordPress root directory.
 */

use PhpOffice\PhpSpreadsheet\IOFactory;

// ==========================================================================
// MAIN AJAX ROUTER
// ==========================================================================

add_action('wp_ajax_swc_cron_import', 'sm_handle_ajax_upload');
function sm_handle_ajax_upload() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized user.', 403);
        return;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Spreadsheet file upload error.', 400);
        return;
    }

    $original_filename = sanitize_file_name($_FILES['file']['name']);

    // *** NEW: Route the request based on the spreadsheet filename. ***
    if ($original_filename === 'Sample-Inventory-Adjustment.xlsx') {
        sm_handle_inventory_sheet();
    } elseif ($original_filename === 'Variables.xlsx') {
        sm_handle_variables_sheet();
    } else {
        sm_handle_product_sheet();
    }
}

/**
 * ==========================================================================
 * EMPTY IMPORT SIGNAL LOGIC
 * ==========================================================================
 * Triggered by server.js when there are absolutely no changes to push.
 * This ensures the admin still receives the "Import Complete" email.
 */
add_action('wp_ajax_sm_handle_empty_import', 'sm_handle_empty_import_handler');
function sm_handle_empty_import_handler() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized user.', 403);
        return;
    }

    // Trigger the unified monitor we built previously.
    // Since there are no jobs scheduled, it will fire the email immediately.
    sm_start_import_monitor();

    wp_send_json_success(['message' => 'Empty import signal received. Completion email triggered.']);
    wp_die();
}

// ==========================================================================
// SAMPLE INVENTORY ADJUSTMENT LOGIC
// ==========================================================================

/**
 * Handles the "Sample Inventory Adjustment.xlsx" spreadsheet.
 */
function sm_handle_inventory_sheet() {
    $file_path = $_FILES['file']['tmp_name'];

    try {
        $spreadsheet = IOFactory::load($file_path);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $rows = [];
        // Read all rows from the second row onwards
        foreach ($worksheet->getRowIterator(2) as $row) {
            $rowData = [
                'sku' => $worksheet->getCell('A' . $row->getRowIndex())->getCalculatedValue(),
                'adjustment' => $worksheet->getCell('B' . $row->getRowIndex())->getCalculatedValue(),
            ];
            if (!empty($rowData['sku'])) {
                 $rows[] = $rowData;
            }
        }

        if (!empty($rows)) {
            // *** CHANGED: Instead of processing directly, we save the data and schedule a "gatekeeper" job. ***
            $upload_dir = wp_upload_dir();
            $temp_inventory_file = $upload_dir['basedir'] . '/inventory_data_' . time() . '_' . uniqid() . '.json';
            file_put_contents($temp_inventory_file, json_encode($rows));

            // Schedule the gatekeeper to run in 5 seconds, giving other jobs time to be scheduled.
            as_schedule_single_action(time() + 5, 'sm_gatekeeper_for_inventory', [
                'inventory_data_path' => $temp_inventory_file
            ], 'sm-inventory-import');
        }
        
        sm_start_import_monitor();
        wp_send_json_success(['message' => 'Inventory spreadsheet received. Gatekeeper job scheduled.']);

    } catch (Exception $e) {
        wp_send_json_error('Error processing inventory spreadsheet: ' . $e->getMessage(), 500);
    } finally {
        wp_die();
    }
}

/**
 * *** NEW: The Gatekeeper Function ***
 * This job checks if the main product import is finished before starting the inventory update.
 */
add_action('sm_gatekeeper_for_inventory', 'sm_gatekeeper_for_inventory_handler', 10, 1);
function sm_gatekeeper_for_inventory_handler($inventory_data_path) {
    // Check if there are any pending actions in the main product import group.
    $pending_product_actions = as_get_scheduled_actions([
        'group' => 'sm-product-import',
        'status' => ActionScheduler_Store::STATUS_PENDING,
    ]);

    if (!empty($pending_product_actions)) {
        // If jobs are still pending, reschedule this gatekeeper to check again in 5 seconds.
        as_schedule_single_action(time() + 5, 'sm_gatekeeper_for_inventory', [
            'inventory_data_path' => $inventory_data_path
        ], 'sm-inventory-import');
        return; // Stop execution
    }

    // If we reach here, no product jobs are pending. It's safe to process the inventory.
    if (file_exists($inventory_data_path)) {
        $inventory_rows = json_decode(file_get_contents($inventory_data_path), true);
        if (is_array($inventory_rows) && !empty($inventory_rows)) {
            $chunks = array_chunk($inventory_rows, 50);
            foreach ($chunks as $chunk) {
                as_schedule_single_action(time(), 'sm_process_inventory_chunk', ['inventory_data' => $chunk], 'sm-inventory-import');
            }
        }
        // Clean up the temporary inventory file.
        @unlink($inventory_data_path);
    }
}

/**
 * Background job to process a chunk of inventory updates.
 */
add_action('sm_process_inventory_chunk', 'sm_process_inventory_chunk_handler', 10, 1);
function sm_process_inventory_chunk_handler($inventory_data) {
    foreach ($inventory_data as $item) {
        $sku = trim($item['sku']);
        // *** CHANGED: Read the adjustment value as a string to check for + or - symbols. ***
        $adjustment_value = trim($item['adjustment']);

        if (empty($sku) || $adjustment_value === '') {
            continue;
        }

        $product_id = wc_get_product_id_by_sku($sku);

        if ($product_id && function_exists('update_field')) {
            $new_inventory = 0;
            // Get the current inventory value from the ACF field.
            $current_inventory = intval(get_field('sample_inventory', $product_id));

            // *** NEW LOGIC: Determine how to apply the adjustment. ***
            if (strpos($adjustment_value, '+') === 0) {
                // If it starts with '+', add to the current inventory.
                $adjustment = intval(substr($adjustment_value, 1));
                $new_inventory = $current_inventory + $adjustment;
            } elseif (is_numeric($adjustment_value) && intval($adjustment_value) < 0) {
                // If it's a negative number, subtract from the current inventory.
                $adjustment = intval($adjustment_value);
                $new_inventory = $current_inventory + $adjustment; // Adding a negative number is subtraction.
            } else {
                // Otherwise, it's a plain number, so set the inventory directly.
                $new_inventory = intval($adjustment_value);
            }

            // Ensure inventory never goes below zero.
            $new_inventory = max(0, $new_inventory);
            
            // Update the field with the final calculated value.
            update_field('sample_inventory', $new_inventory, $product_id);
        }
    }
}

// ==========================================================================
// PRODUCT IMPORT LOGIC (NEW TWO-PHASE PROCESS)
// ==========================================================================

function sm_handle_product_sheet() {
    $file_path = $_FILES['file']['tmp_name'];
    $original_filename = sanitize_file_name($_FILES['file']['name']);
    $category_name = str_replace('.xlsx', '', $original_filename);

    try {
        // Step 1: Save spreadsheet data to a temporary file.
        $spreadsheet = IOFactory::load($file_path);
        $worksheet = $spreadsheet->getActiveSheet();
        $header_row = $worksheet->getRowIterator(1, 1)->current();
        $header_map = [];
        foreach ($header_row->getCellIterator() as $cell) {
            $header_map[trim($cell->getValue())] = $cell->getColumn();
        }
        if (!isset($header_map['Profile No.'])) {
            wp_send_json_error('Spreadsheet is missing "Profile No." column.', 400);
            return;
        }
        $rows = [];
        foreach ($worksheet->getRowIterator(2) as $row) {
            $rowData = [];
            foreach ($header_map as $header_name => $column_letter) {
                 $rowData[$header_name] = $worksheet->getCell($column_letter . $row->getRowIndex())->getCalculatedValue();
            }
            if (!empty(array_filter($rowData))) $rows[] = $rowData;
        }

        $upload_dir = wp_upload_dir();
        $temp_data_file = $upload_dir['basedir'] . '/import_data_' . time() . '_' . uniqid() . '.json';
        file_put_contents($temp_data_file, json_encode($rows));

        // Step 2: Handle creation and deletion lists.
        $logger = wc_get_logger();
        $log_context = ['source' => 'sm_deletion_process'];
        $logger->info("-> Spreadsheet endpoint hit for {$category_name}. Checking for deletion list...", $log_context);

        $profiles_to_run = isset($_POST['profiles_to_run']) ? json_decode(stripslashes($_POST['profiles_to_run']), true) : [];
        $skus_to_delete_raw = isset($_POST['profiles_to_remove']) ? $_POST['profiles_to_remove'] : 'MISSING';
        $skus_to_delete = isset($_POST['profiles_to_remove']) ? json_decode(stripslashes($_POST['profiles_to_remove']), true) : [];
        
        $logger->info("-> RAW profiles_to_remove string: " . print_r($skus_to_delete_raw, true), $log_context);
        $logger->info("-> Decoded skus_to_delete count: " . (is_array($skus_to_delete) ? count($skus_to_delete) : 'FAILED TO DECODE'), $log_context);

        // --- THE PAYLOAD FIX STARTS HERE ---
        if (!empty($profiles_to_run) && is_array($profiles_to_run)) {
            $logger->info("-> Scheduling background creation jobs for " . count($profiles_to_run) . " SKUs.", $log_context);
            $creation_chunks = array_chunk($profiles_to_run, 100);
            foreach ($creation_chunks as $chunk) {
                // Route to a gatekeeper so it waits for Media to finish
                as_schedule_single_action(time() + 5, 'sm_gatekeeper_for_creations', ['skus_to_create' => $chunk], 'sm-product-import');
            }
        }
        
        if (!empty($skus_to_delete) && is_array($skus_to_delete)) {
            $logger->info("-> Scheduling background deletion jobs for " . count($skus_to_delete) . " SKUs.", $log_context);
            // Chunk the deletion payload so Action Scheduler doesn't reject the database insert
            $deletion_chunks = array_chunk($skus_to_delete, 100);
            foreach ($deletion_chunks as $chunk) {
                $action_id = as_schedule_single_action(time(), 'sm_initiate_list_based_deletion', ['skus_to_delete' => $chunk], 'sm-product-import');
                $logger->info("-> Action Scheduler ID created: " . $action_id, $log_context);
            }
        } else {
            $logger->info("-> No valid deletion array found. Skipping deletion scheduling.", $log_context);
        }
        // --- THE PAYLOAD FIX ENDS HERE ---
        
        $import_id = uniqid('import_');

        // Step 3: ALWAYS schedule a gatekeeper for THIS spreadsheet's update process.
        as_schedule_single_action(time() + 10, 'sm_gatekeeper_for_updates', [
            'data_file_path' => $temp_data_file,
            'category_name'  => $category_name,
            'import_id'      => $import_id
        ], 'sm-product-import');
        sm_start_import_monitor();

        wp_send_json_success(['message' => 'Product spreadsheet received. Jobs scheduled.']);
    } catch (Exception $e) {
        wp_send_json_error('Error processing product spreadsheet: ' . $e->getMessage(), 500);
    } finally {
        wp_die();
    }
}

/**
 * *** NEW: Wait for Media before Creating Products ***
 */
add_action('sm_gatekeeper_for_creations', 'sm_gatekeeper_for_creations_handler', 10, 1);
function sm_gatekeeper_for_creations_handler($skus_to_create) {
    $pending_media = as_get_scheduled_actions(['group' => 'sm-media-import', 'status' => ActionScheduler_Store::STATUS_PENDING]);
    $running_media = as_get_scheduled_actions(['group' => 'sm-media-import', 'status' => ActionScheduler_Store::STATUS_RUNNING]);
    
    if (!empty($pending_media) || !empty($running_media)) {
        // Media is still processing! Check again in 15 seconds.
        as_schedule_single_action(time() + 15, 'sm_gatekeeper_for_creations', ['skus_to_create' => $skus_to_create], 'sm-product-import');
        return;
    }
    
    // Media is 100% finished. It's now safe to create the products and attach images.
    as_schedule_single_action(time(), 'sm_create_product_chunk', ['skus_to_create' => $skus_to_create], 'sm-product-import');
}

/**
 * Background job to create a small chunk of products.
 */
add_action('sm_create_product_chunk', 'sm_create_product_chunk_handler', 10, 1);
function sm_create_product_chunk_handler($skus_to_create) {
    foreach ($skus_to_create as $sku) {
        $profile_no = trim($sku);
        if (empty($profile_no)) {
            continue;
        }
        $profile_sku = strtoupper($profile_no);
        $product_id = wc_get_product_id_by_sku($profile_sku);
        if ($product_id) {
            continue;
        }

        $product = new WC_Product_Simple();
        $product->set_sku($profile_sku);
        $product->set_name($profile_no);
        $product->set_status('publish');
        // *** ADDED: Set the standard WooCommerce fields for new products. ***
        $product->set_catalog_visibility('visible');
        $product->set_stock_status('instock');
        $product->set_manage_stock(false);
        $product->set_regular_price('1.00');
        $image_filename = $profile_no . '.png';
        $attachment_id = sm_get_attachment_id_by_filename($image_filename);
        if ($attachment_id) {
            $product->set_image_id($attachment_id);
        }
        $product->save();
    }
}

/**
 * Gatekeeper to ensure updates run after creations.
 */
// <<< FIX: Replace your entire sm_gatekeeper_for_updates_handler function with this one >>>

add_action('sm_gatekeeper_for_updates', 'sm_gatekeeper_for_updates_handler', 10, 3);
function sm_gatekeeper_for_updates_handler($data_file_path, $category_name, $import_id) {
    // 1. Wait for Media
    $pending_media = as_get_scheduled_actions(['group' => 'sm-media-import', 'status' => ActionScheduler_Store::STATUS_PENDING]);
    $running_media = as_get_scheduled_actions(['group' => 'sm-media-import', 'status' => ActionScheduler_Store::STATUS_RUNNING]);
    
    // 2. Wait for Creations
    $pending_creations = as_get_scheduled_actions(['hook' => 'sm_create_product_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING]);
    $running_creations = as_get_scheduled_actions(['hook' => 'sm_create_product_chunk', 'status' => ActionScheduler_Store::STATUS_RUNNING]);
    $pending_gates = as_get_scheduled_actions(['hook' => 'sm_gatekeeper_for_creations', 'status' => ActionScheduler_Store::STATUS_PENDING]);
    $running_gates = as_get_scheduled_actions(['hook' => 'sm_gatekeeper_for_creations', 'status' => ActionScheduler_Store::STATUS_RUNNING]);

    if (!empty($pending_media) || !empty($running_media) || !empty($pending_creations) || !empty($running_creations) || !empty($pending_gates) || !empty($running_gates)) {
        // Prerequisite jobs are still running, reschedule this check.
        as_schedule_single_action(time() + 15, 'sm_gatekeeper_for_updates', [
            'data_file_path' => $data_file_path,
            'category_name'  => $category_name,
            'import_id'      => $import_id
        ], 'sm-product-import');
        return;
    }

    // --- NEW COUNTER LOGIC ---
    if (file_exists($data_file_path)) {
        $all_rows = json_decode(file_get_contents($data_file_path), true);
        if (is_array($all_rows) && !empty($all_rows)) {
            $chunk_size = 100;
            $total_chunks = ceil(count($all_rows) / $chunk_size);
            set_transient('import_counter_' . $import_id, $total_chunks, DAY_IN_SECONDS);
        }
    }
    // --- END NEW COUNTER LOGIC ---

    as_schedule_single_action(time(), 'sm_process_spreadsheet_batch', [
        'data_file_path' => $data_file_path,
        'category_name'  => $category_name,
        'batch_offset'   => 0,
        'import_id'      => $import_id
    ], 'sm-product-import');
}

/**
 * *** UPDATED: This "controller" function now works with a file path. ***
 * It reads the data file, processes a slice of it, and then reschedules
 * itself for the next slice, deleting the file when complete.
 */
// <<< FIX: Replace your entire sm_process_spreadsheet_batch_handler function with this one >>>

add_action('sm_process_spreadsheet_batch', 'sm_process_spreadsheet_batch_handler', 10, 4);
function sm_process_spreadsheet_batch_handler($data_file_path, $category_name, $batch_offset, $import_id) {
    if (!file_exists($data_file_path)) return;

    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $batch_size = 500; // You can adjust this as needed
    $rows_for_this_batch = array_slice($all_rows, $batch_offset, $batch_size);

    if (!empty($rows_for_this_batch)) {
        $chunk_size = 100; // Must match the chunk_size in the gatekeeper.
        $chunk_count = ceil(count($rows_for_this_batch) / $chunk_size);

        for ($i = 0; $i < $chunk_count; $i++) {
            $chunk_start_index = $batch_offset + ($i * $chunk_size);
            as_schedule_single_action(time(), 'sm_process_product_update_chunk', [
                'data_file_path' => $data_file_path,
                'category_name'  => $category_name,
                'start_index'    => $chunk_start_index,
                'count'          => $chunk_size,
                'import_id'      => $import_id
            ], 'sm-product-import');
        }
    }

    $next_offset = $batch_offset + $batch_size;
    if ($next_offset < count($all_rows)) {
        as_schedule_single_action(time(), 'sm_process_spreadsheet_batch', [
            'data_file_path' => $data_file_path,
            'category_name'  => $category_name,
            'batch_offset'   => $next_offset,
            'import_id'      => $import_id
        ], 'sm-product-import');
    }
}

/**
 * *** RENAMED & SIMPLIFIED: This function now ONLY updates existing products. ***
 */
add_action('sm_process_product_update_chunk', 'sm_process_product_update_chunk_handler', 10, 5);
function sm_process_product_update_chunk_handler($data_file_path, $category_name, $start_index, $count, $import_id) {
    if (!file_exists($data_file_path)) return;
    
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;

    $rows = array_slice($all_rows, $start_index, $count);

    // 4. Process the rows as before.
    foreach ($rows as $row) {
        try {

        $profile_no = trim($row['Profile No.']); 
        if (empty($profile_no)) continue;

        $product_id = wc_get_product_id_by_sku($profile_no);

        // If the product doesn't exist at this point, skip it.
        if (!$product_id) continue;
        
        $product = wc_get_product($product_id);     


        
        // Set Product Image by matching SKU to filename
        $image_filename = $profile_no . '.png';
        $attachment_id = sm_get_attachment_id_by_filename($image_filename);
        if ($attachment_id) {
            $product->set_image_id($attachment_id);
        }
        
        // Set Categories
        $category_ids = [];
        $term_data = ['Molding', $category_name];
        foreach($term_data as $term_name) {
            $term = get_term_by('name', $term_name, 'product_cat');
            if ($term) {
                $category_ids[] = $term->term_id;
            } else {
                $new_term = wp_insert_term($term_name, 'product_cat');
                if (!is_wp_error($new_term)) $category_ids[] = $new_term['term_id'];
            }
        }
        $uncategorized_id = get_option('default_product_cat');
        if ($uncategorized_id) $category_ids[] = $uncategorized_id;
        $product->set_category_ids(array_unique($category_ids));

        // Set Tags
        // *** FIXED: Correctly get and set product tags. ***
        $tags_string = isset($row['Tags']) ? trim($row['Tags']) : '';
        if (!empty($tags_string)) {
            $tag_names = array_map('trim', explode(',', $tags_string));
            $tag_ids = [];
            foreach ($tag_names as $tag_name) {
                // Check if the tag already exists
                $term = get_term_by('name', $tag_name, 'product_tag');
                if ($term) {
                    $tag_ids[] = $term->term_id;
                } else {
                    // If the tag doesn't exist, create it and get its ID
                    $new_term = wp_insert_term($tag_name, 'product_tag');
                    if (!is_wp_error($new_term)) {
                        $tag_ids[] = $new_term['term_id'];
                    }
                }
            }
            // Set the tag IDs on the product
            $product->set_tag_ids($tag_ids);
        }

        // =========================================================================
        // THE HASH GATEKEEPER (Bulletproof Normalized Version + Logging)
        // =========================================================================
        $normalized_data = '';
        foreach ($row as $val) {
            $normalized_data .= trim((string)$val) . '|'; 
        }
        $row_hash = md5($normalized_data);
        $stored_hash = get_post_meta($product_id, '_sm_import_hash', true);
        if ($row_hash === $stored_hash) {
            $product->save();
            continue; 
        }
        // =========================================================================

        // Update ACF Fields
        if (function_exists('update_field')) {
            $get_val = fn($h) => isset($row[$h]) ? $row[$h] : null;

            $get_numeric_val = function($header_name) use ($row) {
                $val = isset($row[$header_name]) ? $row[$header_name] : null;
                // Only convert to float if it's a valid number and not empty.
                if (is_numeric($val) && $val !== '') {
                    return floatval($val);
                }
                return ''; // Return an empty string for blank cells.
            };

            $get_checkbox_values = function($header_name) use ($get_val) {
                $value_string = trim($get_val($header_name));
                if (!empty($value_string)) {
                    // Split comma-separated values into an array for ACF.
                    return array_map('trim', explode(',', $value_string));
                }
                return []; // Return an empty array to clear the field if the cell is blank.
            };            

            update_field('style', $get_checkbox_values('Style'), $product_id);
            update_field('style_2', $get_checkbox_values('Style 2'), $product_id);
            update_field('catalog_page_number', $get_numeric_val('Catalog Page Number'), $product_id);
            update_field('thickness', $get_numeric_val('Thickness'), $product_id);
            update_field('width', $get_numeric_val('Width'), $product_id);
            update_field('input_thickness', $get_numeric_val('Input Thickness'), $product_id);
            update_field('input_width', $get_numeric_val('Input Width'), $product_id);
            update_field('min_thickness', $get_numeric_val('Min Thickness'), $product_id);
            update_field('max_thickness', $get_numeric_val('Max Thickness'), $product_id);
            update_field('min_width', $get_numeric_val('Min Width'), $product_id);
            update_field('max_width', $get_numeric_val('Max Width'), $product_id);
            update_field('projection_from_wall', $get_numeric_val('Projection from Wall'), $product_id);
            update_field('projection_from_ceiling', $get_numeric_val('Projection from Ceiling'), $product_id);
            update_field('markup', $get_numeric_val('Markup'), $product_id);
            update_field('waste', $get_numeric_val('Waste'), $product_id);
            update_field('tags', trim($get_val('Tags')), $product_id);
            update_field('related_casings', trim($get_val('Related Casings')), $product_id);
            update_field('related_crowns', trim($get_val('Related Crowns')), $product_id);
            update_field('related_baseboards', trim($get_val('Related Baseboards')), $product_id);
            update_field('plinths', trim($get_val('Plinths')), $product_id);
            update_field('relief_angle', $get_numeric_val('ReliefAngle'), $product_id);
            update_field('back_relief', $get_numeric_val('BackRelief'), $product_id);
            update_field('type', trim($get_val('Type')), $product_id);
            update_field('1strabbetnotch_posno1_minallowedleftovermaterialthickness', $get_numeric_val('1stRabbetNotch_PosNo1_MinAllowedLeftoverMaterialThickness'), $product_id);
            update_field('1strabbetnotch_posno2_minallowedleftovermaterialthickness', $get_numeric_val('1stRabbetNotch_PosNo2_MinAllowedLeftoverMaterialThickness'), $product_id);
            update_field('1strabbetnotch_posno3_minallowedleftovermaterialthickness', $get_numeric_val('1stRabbetNotch_PosNo3_MinAllowedLeftoverMaterialThickness'), $product_id);
            update_field('1strabbetnotch_posno4_minallowedleftovermaterialthickness', $get_numeric_val('1stRabbetNotch_PosNo4_MinAllowedLeftoverMaterialThickness'), $product_id);
            update_field('1strabbetnotch', $get_numeric_val('1stRabbetNotch'), $product_id);
            update_field('1strabbetnotch_thickness', $get_numeric_val('1stRabbetNotch_Thickness'), $product_id);
            update_field('1strabbetnotch_width', $get_numeric_val('1stRabbetNotch_Width'), $product_id);
            update_field('1strabbetnotch_maxwidth', $get_numeric_val('1stRabbetNotch_MaxWidth'), $product_id);

            /**
             * ----------------------------------------------------------------
             * STEP 2: AUTO-CALCULATE TAXONOMIES (RANGES)
             * ----------------------------------------------------------------
             */
            if ( function_exists( 'sm_calculate_molding_terms' ) ) {
                
                // 1. THICKNESS RANGE
                // Fix: Force floatval() to ensure empty strings become 0, triggering the fallback logic.
                $val_thick = floatval( $get_numeric_val('Thickness') );
                $min_thick = floatval( $get_numeric_val('Min Thickness') );
                $max_thick = floatval( $get_numeric_val('Max Thickness') );
                
                $terms = sm_calculate_molding_terms( 'thickness', $val_thick, $min_thick, $max_thick );
                wp_set_object_terms( $product_id, $terms, 'molding_thickness_range' );

                // 2. WIDTH RANGE
                $val_width = floatval( $get_numeric_val('Width') );
                $min_width = floatval( $get_numeric_val('Min Width') );
                $max_width = floatval( $get_numeric_val('Max Width') );

                $terms = sm_calculate_molding_terms( 'width', $val_width, $min_width, $max_width );
                wp_set_object_terms( $product_id, $terms, 'molding_width_range' );

                // 3. WALL PROJECTION
                $val_wall = $get_numeric_val('Projection from Wall');
                if ( $val_wall !== '' ) {
                    $terms = sm_calculate_molding_terms( 'wall_proj', $val_wall );
                    wp_set_object_terms( $product_id, $terms, 'molding_wall_proj' );
                } else {
                    wp_set_object_terms( $product_id, [], 'molding_wall_proj' );
                }

                // 4. CEILING PROJECTION
                $val_ceil = $get_numeric_val('Projection from Ceiling');
                if ( $val_ceil !== '' ) {
                    $terms = sm_calculate_molding_terms( 'ceil_proj', $val_ceil );
                    wp_set_object_terms( $product_id, $terms, 'molding_ceil_proj' );
                } else {
                    wp_set_object_terms( $product_id, [], 'molding_ceil_proj' );
                }
            }
        }

        // =========================================================================
        // SAVE THE NEW HASH
        // =========================================================================
        update_post_meta($product_id, '_sm_import_hash', $row_hash);

        $product->save();

        } catch (Throwable $e) {
            // If any error happens above, it gets caught and logged here
            $error_sku = isset($row['Profile No.']) ? $row['Profile No.'] : 'SKU UNKNOWN';
            wc_get_logger()->error(
                'CRITICAL ERROR processing SKU: ' . $error_sku . ' | Message: ' . $e->getMessage() . ' | File: ' . basename($e->getFile()) . ' | Line: ' . $e->getLine(),
                ['source' => 'import_fatal_errors'] // Log to a new, separate file
            );
            // Continue to the next row so one bad row doesn't stop the whole chunk
            continue; 
        }

    }

        // --- DECREMENT COUNTER LOGIC START ---
    $counter_key = 'import_counter_' . $import_id;
    $current_job_count = get_transient($counter_key);

    if ($current_job_count !== false) {
        $new_count = (int)$current_job_count - 1;

        if ($new_count <= 0) {
            // This is the LAST job, so clean up.
            delete_transient($counter_key);
            as_schedule_single_action(time(), 'sm_cleanup_temp_import_file', ['file_path' => $data_file_path]);
        } else {
            // More jobs remaining, just update the count.
            set_transient($counter_key, $new_count, DAY_IN_SECONDS);
        }
    }
    // --- DECREMENT COUNTER LOGIC END ---
}

add_action('sm_cleanup_temp_import_file', 'sm_cleanup_temp_import_file_handler', 10, 1);
function sm_cleanup_temp_import_file_handler($file_path) {
    if (file_exists($file_path)) {
        @unlink($file_path);
    }
}

function sm_get_attachment_id_by_filename($filename) {
    global $wpdb;
    $query = $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
        '%' . $wpdb->esc_like($filename)
    );
    // FIX: Get an array of all matches so we can loop through them
    $attachment_ids = $wpdb->get_col($query);
    
    if (!empty($attachment_ids)) {
        foreach ($attachment_ids as $attachment_id) {
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (basename($attached_file) === $filename) {
                return (int) $attachment_id;
            }
        }
    }
    return null;
}

// ==========================================================================
// DELETION LOGIC
// ==========================================================================

/**
 * NEW: Handles a standalone deletion request that doesn't include a spreadsheet.
 * This is triggered from server.js when only deletions are needed.
 */
add_action('wp_ajax_sm_handle_standalone_deletion', 'sm_handle_standalone_deletion_handler');
function sm_handle_standalone_deletion_handler() {
    $logger = wc_get_logger();
    $log_context = ['source' => 'sm_deletion_process'];
    $logger->info("-> Standalone deletion endpoint hit.", $log_context);

    if (!current_user_can('manage_woocommerce')) {
        $logger->error("-> Unauthorized user blocked from standalone deletion.", $log_context);
        wp_send_json_error('Unauthorized user.', 403);
        return;
    }

    $raw_post_data = isset($_POST['profiles_to_remove']) ? $_POST['profiles_to_remove'] : 'MISSING';
    $skus_to_delete = isset($_POST['profiles_to_remove']) ? json_decode(stripslashes($_POST['profiles_to_remove']), true) : [];
    
    if (empty($skus_to_delete) || !is_array($skus_to_delete)) {
        $logger->error("-> Failed to decode profiles_to_remove or array is empty.", $log_context);
        wp_send_json_error('No profiles provided for deletion.', 400);
        return;
    }

    $logger->info("-> Decoded " . count($skus_to_delete) . " SKUs. Chunking and scheduling...", $log_context);

    // Chunk the standalone payload to prevent 504 timeouts and Action Scheduler rejections
    $deletion_chunks = array_chunk($skus_to_delete, 100);
    foreach ($deletion_chunks as $chunk) {
        as_schedule_single_action(time(), 'sm_initiate_list_based_deletion', ['skus_to_delete' => $chunk], 'sm-product-import');
    }

    $logger->info("-> Standalone deletion successfully handed off to background jobs.", $log_context);
    sm_start_import_monitor();
    wp_send_json_success(['message' => 'Deletion-only jobs scheduled.']);
    wp_die();
}

add_action('sm_initiate_list_based_deletion', 'sm_initiate_list_based_deletion_handler', 10, 1);
function sm_initiate_list_based_deletion_handler($skus_to_delete) {
    $logger = wc_get_logger();
    $log_context = ['source' => 'sm_deletion_process'];
    
    $logger->info('--- DELETION PROCESS STARTED ---', $log_context);
    $logger->info('Raw SKUs received: ' . print_r($skus_to_delete, true), $log_context);

    if (empty($skus_to_delete) || !is_array($skus_to_delete)) {
        $logger->error('Deletion aborted: SKUs array is empty or invalid.', $log_context);
        return;
    }

    $protected_skus = ['XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS', 'KNIFECOST', 'SETUPCHARGE'];
    $filtered_skus_to_delete = array_diff($skus_to_delete, $protected_skus);

    $products_to_delete = [];
    foreach ($filtered_skus_to_delete as $sku) {
        if (is_array($sku)) {
            $sku = isset($sku['Profile No.']) ? $sku['Profile No.'] : (isset($sku['sku']) ? $sku['sku'] : current($sku));
        }
        $clean_sku = trim((string)$sku);
        $product_id = wc_get_product_id_by_sku(strtoupper($clean_sku));
        
        $logger->info("SKU Check: '{$clean_sku}' -> Found Product ID: " . ($product_id ? $product_id : 'NONE'), $log_context);
        
        $products_to_delete[] = [
            'id' => $product_id ? $product_id : 0, 
            'sku' => $clean_sku 
        ];
    }
    
    if (!empty($products_to_delete)) {
        $delete_chunks = array_chunk($products_to_delete, 100);
        $logger->info('Scheduling ' . count($delete_chunks) . ' chunks for background deletion.', $log_context);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_product_chunk', ['products' => $chunk], 'sm-product-import');
        }
    } else {
        $logger->info('No valid products to delete after filtering.', $log_context);
    }
}

add_action('sm_delete_product_chunk', 'sm_delete_product_chunk_handler', 10, 1);
function sm_delete_product_chunk_handler($products) {
    global $wpdb;
    $logger = wc_get_logger();
    $log_context = ['source' => 'sm_deletion_process'];
    $logger->info('--- PROCESSING DELETION CHUNK ---', $log_context);

    foreach ($products as $product) {
        $product_id = $product['id'];
        $product_sku = $product['sku'];

        $logger->info("Processing deletion for SKU: '{$product_sku}' (Product ID: {$product_id})", $log_context);

        if (!empty($product_sku)) {
            $file_endings = ['.png', '-1.png', '.dxf', '.pdf'];
            foreach ($file_endings as $ending) {
                $filename = $product_sku . $ending;
                
                $query = $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                    '%' . $wpdb->esc_like($filename)
                );
                $attachment_ids = $wpdb->get_col($query);
                
                if (!empty($attachment_ids)) {
                    foreach ($attachment_ids as $attachment_id) {
                        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
                        if (basename($attached_file) === $filename) {
                            $logger->info("-> Found Attachment ID {$attachment_id} for '{$filename}'. Attempting to delete...", $log_context);
                            
                            // This is where EWWW or S3 offload plugins usually crash
                            wp_delete_attachment($attachment_id, true);
                            
                            $logger->info("-> Attachment ID {$attachment_id} deleted successfully.", $log_context);
                        }
                    }
                }
            }
        }
        
        if (!empty($product_id)) {
            $logger->info("-> Attempting to delete Product ID {$product_id}...", $log_context);
            wp_delete_post($product_id, true);
            $logger->info("-> Product ID {$product_id} deleted successfully.", $log_context);
        }
    }
    $logger->info('--- CHUNK PROCESSING COMPLETE ---', $log_context);
}

// ==========================================================================
// EFFICIENT MEDIA IMPORT LOGIC (COMBINED ZIP AND PROCESS)
// ==========================================================================

/**
 * *** NEW: A single endpoint to handle the combined media zip file. ***
 */
add_action('wp_ajax_sm_handle_combined_media_zip', 'sm_handle_combined_media_zip_handler');
function sm_handle_combined_media_zip_handler() {
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized user.', 403);
        return;
    }
    if (!isset($_FILES['combined_media_zip']) || $_FILES['combined_media_zip']['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_error('Combined media zip file upload error.', 400);
        return;
    }
    if (!isset($_POST['img_folder_id']) || !is_numeric($_POST['img_folder_id']) || !isset($_POST['dxf_folder_id']) || !is_numeric($_POST['dxf_folder_id'])) {
        wp_send_json_error('Missing or invalid Real Media Library folder IDs.', 400);
        return;
    }

    $upload_dir = wp_upload_dir();
    $temp_zip_path = $upload_dir['basedir'] . '/temp_combined_media_' . time() . '.zip';
    if (!move_uploaded_file($_FILES['combined_media_zip']['tmp_name'], $temp_zip_path)) {
        wp_send_json_error('Failed to save temporary combined zip file.', 500);
        return;
    }

    as_schedule_single_action(time(), 
        'sm_process_combined_media_zip',
        [
            'zip_path' => $temp_zip_path, 
            'img_folder_id' => (int)$_POST['img_folder_id'],
            'dxf_folder_id' => (int)$_POST['dxf_folder_id']
        ],
        'sm-media-import'
    );
    sm_start_import_monitor();

    wp_send_json_success(['message' => 'Combined media zip received and scheduled for processing.']);
    wp_die();
}

/**
 * *** NEW: Background job to process the single combined zip file. ***
 */
add_action('sm_process_combined_media_zip', 'sm_process_combined_media_zip_handler', 10, 3);
function sm_process_combined_media_zip_handler($zip_path, $img_folder_id, $dxf_folder_id) {
    if (!class_exists('ZipArchive') || !file_exists($zip_path)) return;

    $zip = new ZipArchive;
    if ($zip->open($zip_path) !== TRUE) {
        @unlink($zip_path);
        return;
    }

    $upload_dir = wp_upload_dir();
    $temp_extract_path = $upload_dir['basedir'] . '/temp_combined_extract_' . time() . '/';
    wp_mkdir_p($temp_extract_path);

    $zip->extractTo($temp_extract_path);
    $zip->close();

    $files_to_process = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_extract_path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDir()) continue;
        $files_to_process[] = $fileinfo->getPathname();
    }

    // --- THE TIMEOUT FIX: Process 20 media files per background job ---
    $file_chunks = array_chunk($files_to_process, 20); 
    foreach ($file_chunks as $chunk) {
        as_schedule_single_action(time(), 'sm_process_media_chunk', [
            'file_paths' => $chunk,
            'img_folder_id' => $img_folder_id,
            'dxf_folder_id' => $dxf_folder_id
        ], 'sm-media-import');
    }

    // Schedule cleanup job to remove the extraction folder after all chunks finish
    as_schedule_single_action(time() + 15, 'sm_cleanup_media_extraction', [
        'extract_path' => $temp_extract_path,
        'zip_path' => $zip_path
    ], 'sm-media-import');
}

/**
 * Background worker that safely sideloads/offloads small chunks of media
 */
add_action('sm_process_media_chunk', 'sm_process_media_chunk_handler', 10, 3);
function sm_process_media_chunk_handler($file_paths, $img_folder_id, $dxf_folder_id) {
    $image_attachment_ids = [];
    $dxf_attachment_ids = [];
    
    foreach ($file_paths as $filepath) {
        if (!file_exists($filepath)) continue;
        
        $filename = basename($filepath);
        $parent_dir = basename(dirname($filepath));
        $is_image = ($parent_dir === 'images');
        $is_dxf = ($parent_dir === 'dxfs');

        $existing_attachment_id = sm_get_attachment_id_by_filename($filename);
        if ($existing_attachment_id) {
            wp_delete_attachment($existing_attachment_id, true);
        }

        if ( ! $is_image ) {
            add_filter('as3cf_pre_update_attachment_metadata', '__return_false', 9999);
            add_filter('as3cf_pre_upload_attachment', '__return_false', 9999); 
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $filepath
        ];

        $attach_id = media_handle_sideload($file_array, 0);

        if ( ! $is_image ) {
            remove_filter('as3cf_pre_update_attachment_metadata', '__return_false', 9999);
            remove_filter('as3cf_pre_upload_attachment', '__return_false', 9999);
        }

        if (is_wp_error($attach_id)) {
            wc_get_logger()->error('Media Import Error for ' . $filename . ': ' . $attach_id->get_error_message(), ['source' => 'import_media_errors']);
            continue; 
        }
        
        if ($is_image) {
            $image_attachment_ids[] = $attach_id;
        } elseif ($is_dxf) {
            $dxf_attachment_ids[] = $attach_id;
        }
    }

    if (!empty($image_attachment_ids) && function_exists('wp_rml_move')) {
        wp_rml_move($img_folder_id, $image_attachment_ids);
    }
    if (!empty($dxf_attachment_ids) && function_exists('wp_rml_move')) {
        wp_rml_move($dxf_folder_id, $dxf_attachment_ids);
    }
}

/**
 * Gatekeeper to clean up the temporary media folder once all chunks are done
 */
add_action('sm_cleanup_media_extraction', 'sm_cleanup_media_extraction_handler', 10, 2);
function sm_cleanup_media_extraction_handler($extract_path, $zip_path) {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_media_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING]);
    $running_actions = as_get_scheduled_actions(['hook' => 'sm_process_media_chunk', 'status' => ActionScheduler_Store::STATUS_RUNNING]);
    
    if (!empty($pending_actions) || !empty($running_actions)) {
        as_schedule_single_action(time() + 15, 'sm_cleanup_media_extraction', ['extract_path' => $extract_path, 'zip_path' => $zip_path], 'sm-media-import');
        return;
    }
    
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once (ABSPATH . '/wp-admin/includes/file.php');
        WP_Filesystem();
    }
    if (file_exists($extract_path)) {
        $wp_filesystem->rmdir($extract_path, true);
    }
    @unlink($zip_path);
}

// ====================================================================================
//                   VARIABLES SPREADSHEET IMPORT LOGIC                        START
// ====================================================================================

/**
 * Handles the "Variables.xlsx" spreadsheet upload.
 * Now uses specialized helper functions for CPTs vs. Shipping Zones.
 */
function sm_handle_variables_sheet() {
    $file_path = $_FILES['file']['tmp_name'];

    try {
        $spreadsheet = IOFactory::load($file_path);

        sm_update_starke_commerce_options($spreadsheet);

        // --- PROCESS CPTs ---
        $cpt_sheets = [
            'Finish Options' => ['id_column' => 'Finish',       'process_hook' => 'sm_process_finish_options_chunk', 'cleanup_hook' => 'sm_finish_options_cleanup_gatekeeper', 'group' => 'sm-variables-import'],
            'Species'        => ['id_column' => 'Species',      'process_hook' => 'sm_process_species_chunk',        'cleanup_hook' => 'sm_species_cleanup_gatekeeper',        'group' => 'sm-species-import'],
            'Stain Colors'   => ['id_column' => 'Stain Colors', 'process_hook' => 'sm_process_stain_colors_chunk',   'cleanup_hook' => 'sm_stain_colors_cleanup_gatekeeper',   'group' => 'sm-stain-colors-import'],
            'Sheen Levels'   => ['id_column' => 'Sheen Levels', 'process_hook' => 'sm_process_sheen_levels_chunk',   'cleanup_hook' => 'sm_sheen_levels_cleanup_gatekeeper',   'group' => 'sm-sheen-levels-import'],
            'Lengths'        => ['id_column' => 'Lengths',      'process_hook' => 'sm_process_lengths_chunk',        'cleanup_hook' => 'sm_lengths_cleanup_gatekeeper',        'group' => 'sm-lengths-import'],
        ];

        foreach ($cpt_sheets as $sheet_name => $config) {
            $worksheet = $spreadsheet->getSheetByName($sheet_name);
            if ($worksheet) {
                sm_schedule_cpt_jobs($worksheet, $config['id_column'], $config['process_hook'], $config['cleanup_hook'], $config['group']);
            }
        }

        // --- PROCESS SHIPPING ZONES ---
        $shipping_worksheet = $spreadsheet->getSheetByName('Shipping');
        if ($shipping_worksheet) {
            sm_schedule_shipping_jobs($shipping_worksheet);
        }
        
        sm_start_import_monitor();
        wp_send_json_success(['message' => 'Variables spreadsheet received. All processing and cleanup jobs have been scheduled.']);
    } catch (Exception $e) {
        wp_send_json_error('Error processing Variables spreadsheet: ' . $e->getMessage(), 500);
    } finally {
        wp_die();
    }
}

/**
 * NEW HELPER for CPTs (Finish Options, Species): Schedules file-based chunks.
 */
function sm_schedule_cpt_jobs($worksheet, $id_column, $process_hook, $cleanup_hook, $group) {
    $sheet_title = $worksheet->getTitle();
    $header_row = $worksheet->getRowIterator(1, 1)->current();
    $header_map = [];
    foreach ($header_row->getCellIterator() as $cell) {
        $header_name = trim($cell->getValue());
        if (!empty($header_name)) $header_map[$header_name] = $cell->getColumn();
    }
    if (!isset($header_map[$id_column])) return;
    
    $rows = [];
    foreach ($worksheet->getRowIterator(2) as $row) {
        $row_data = [];
        $is_row_empty = true;
        foreach ($header_map as $header_name => $column_letter) {
            $cell_value = $worksheet->getCell($column_letter . $row->getRowIndex())->getCalculatedValue();
            $row_data[$header_name] = $cell_value;
            if (!empty($cell_value)) $is_row_empty = false;
        }
        if (!$is_row_empty) $rows[] = $row_data;
    }

    if (!empty($rows)) {
        // --- FILE BASED PAYLOAD FIX ---
        $upload_dir = wp_upload_dir();
        $safe_title = sanitize_title($sheet_title);
        $temp_file_path = $upload_dir['basedir'] . '/' . $group . '_' . $safe_title . '_' . time() . '_' . uniqid() . '.json';
        
        // Save the data to a JSON file
        file_put_contents($temp_file_path, json_encode($rows));

        // Now perfectly safe to process 100 rows per job without hitting DB limits
        $chunk_size = 100; 
        $total_rows = count($rows);
        $chunk_count = ceil($total_rows / $chunk_size);

        for ($i = 0; $i < $chunk_count; $i++) {
            $start_index = $i * $chunk_size;
            as_schedule_single_action(time(), $process_hook, [
                'data_file_path' => $temp_file_path,
                'start_index'    => $start_index,
                'count'          => $chunk_size
            ], $group);
        }

        $valid_ids = array_map('trim', array_column($rows, $id_column));
        as_schedule_single_action(time() + 10, $cleanup_hook, [
            'valid_ids'      => $valid_ids,
            'data_file_path' => $temp_file_path // Pass the file so the cleanup job can delete it
        ], $group);
    }
}


/**
 * NEW HELPER for Shipping Zones: Groups data to prevent race conditions.
 */
function sm_schedule_shipping_jobs($worksheet) {
    $id_column = 'Shipping';
    $process_hook = 'sm_process_shipping_chunk';
    $cleanup_hook = 'sm_shipping_cleanup_gatekeeper';
    $group = 'sm-shipping-import';

    // This function reads all rows from the worksheet
    $header_row = $worksheet->getRowIterator(1, 1)->current();
    $header_map = [];
    foreach ($header_row->getCellIterator() as $cell) {
        $header_name = trim($cell->getValue());
        if (!empty($header_name)) $header_map[$header_name] = $cell->getColumn();
    }
    if (!isset($header_map[$id_column])) return;
    $rows = [];
    foreach ($worksheet->getRowIterator(2) as $row) {
        $row_data = [];
        $is_row_empty = true;
        foreach ($header_map as $header_name => $column_letter) {
            $cell_value = $worksheet->getCell($column_letter . $row->getRowIndex())->getCalculatedValue();
            $row_data[$header_name] = $cell_value;
            if (!empty($cell_value)) $is_row_empty = false;
        }
        if (!$is_row_empty) $rows[] = $row_data;
    }

    if (!empty($rows)) {
        // Groups all rows by Zone Name
        $grouped_data = [];
        foreach ($rows as $row) {
            $id_value = trim($row[$id_column]);
            if (!empty($id_value)) $grouped_data[$id_value][] = $row;
        }

        // Gathers all unique sample methods for later use
        if ($group === 'sm-shipping-import') {
            $all_sample_methods = [];
            $unique_sample_titles = [];
            foreach ($rows as $row) {
                $title = isset($row['Samples Shipping Methods']) ? trim($row['Samples Shipping Methods']) : '';
                $price = isset($row['Samples Shipping Method Price']) ? trim($row['Samples Shipping Method Price']) : '0';
                if (!empty($title) && !in_array($title, $unique_sample_titles)) {
                    $all_sample_methods[] = ['title' => $title, 'price' => $price];
                    $unique_sample_titles[] = $title;
                }
            }
            if (!empty($all_sample_methods)) {
                $grouped_data['all_sample_methods'] = $all_sample_methods;
            }
        }

        $unique_ids = array_keys($grouped_data);
        if (($key = array_search('all_sample_methods', $unique_ids)) !== false) {
            unset($unique_ids[$key]);
        }

        // Saves the grouped data to a single file
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/' . $group . '_data_' . time() . '_' . uniqid() . '.json';
        file_put_contents($temp_file, json_encode($grouped_data));

        // Chunks and schedules jobs based on UNIQUE zone names
        $chunks = array_chunk($unique_ids, 50);
        foreach ($chunks as $chunk_of_ids) {
            as_schedule_single_action(time(), $process_hook, ['ids_to_process' => $chunk_of_ids, 'data_file_path' => $temp_file], $group);
        }

        $valid_ids = array_map('trim', $unique_ids);
        as_schedule_single_action(time() + 10, $cleanup_hook, ['valid_ids' => $valid_ids, 'sample_methods' => $all_sample_methods], $group);
    }
}

// ==========================================================================
// FINISH OPTIONS WORKSHEET IMPORT LOGIC
// ==========================================================================

add_action('sm_process_finish_options_chunk', 'sm_process_finish_options_chunk_handler', 10, 3);
function sm_process_finish_options_chunk_handler($data_file_path, $start_index, $count) {
    if (!file_exists($data_file_path)) return;
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $finish_options_data = array_slice($all_rows, $start_index, $count);

    foreach ($finish_options_data as $index => $row) {
        $menu_order = (int)$start_index + $index;
        $finish_name = trim($row['Finish']);
        if (empty($finish_name)) continue;

        $post_id = null;
        $existing_post = get_page_by_title($finish_name, OBJECT, 'finish');

        if ($existing_post) {
            $post_id = $existing_post->ID;
            $update_args = ['ID' => $post_id, 'menu_order' => $menu_order];
            if ($existing_post->post_status === 'trash') $update_args['post_status'] = 'publish';
            wp_update_post($update_args);
        } else {
            $post_args = [
                'post_title'   => $finish_name,
                'post_name'    => sanitize_title($finish_name),
                'post_status'  => 'publish',
                'post_type'    => 'finish',
                'menu_order'   => $menu_order,
            ];
            $post_id = wp_insert_post($post_args);
        }

        if ($post_id && !is_wp_error($post_id) && function_exists('update_field')) {
            $get_numeric_val = function($header_name) use ($row) {
                $val = isset($row[$header_name]) ? $row[$header_name] : null;
                return is_numeric($val) ? floatval($val) : 0;
            };

            update_field('cost_per_inch_in_width', $get_numeric_val('Charge per inch in width'), $post_id);
            update_field('minimum_cost_per_linear_foot', $get_numeric_val('Minimum charge allowed'), $post_id);
            update_field('maximum_cost_per_linear_foot', $get_numeric_val('Maximum charge allowed'), $post_id);
            update_field('show_paint_fields', $get_numeric_val('Show paint fields'), $post_id);
            update_field('show_stain_fields', $get_numeric_val('Show stain fields'), $post_id);
        }
    }
}

// ==========================================================================
// FINISH OPTIONS DELETION LOGIC
// ==========================================================================

add_action('sm_finish_options_cleanup_gatekeeper', 'sm_finish_options_cleanup_gatekeeper_handler', 10, 2);
function sm_finish_options_cleanup_gatekeeper_handler($valid_finish_names, $data_file_path = '') {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_finish_options_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'sm-variables-import']);

    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 5, 'sm_finish_options_cleanup_gatekeeper', ['valid_finish_names' => $valid_finish_names, 'data_file_path' => $data_file_path], 'sm-variables-import');
        return; 
    }

    // Delete the temporary file now that updates are done
    if (!empty($data_file_path) && file_exists($data_file_path)) {
        @unlink($data_file_path);
    }

    as_schedule_single_action(time(), 'sm_initiate_finish_options_deletion', ['valid_finish_names' => $valid_finish_names], 'sm-variables-import');
}


/**
 * Compares site posts with the spreadsheet list and schedules deletions.
 */
add_action('sm_initiate_finish_options_deletion', 'sm_initiate_finish_options_deletion_handler', 10, 1);
function sm_initiate_finish_options_deletion_handler($valid_finish_names) {
    // The argument $valid_finish_names is now received directly.

    // Get all 'finish' posts currently on the site.
    $all_site_posts = get_posts([
        'post_type'      => 'finish',
        'post_status'    => 'any', // Get all posts regardless of status
        'posts_per_page' => -1,   // Get all of them
    ]);

    $site_titles_map = [];
    foreach ($all_site_posts as $post) {
        // Create a map of Post Title => Post ID
        $site_titles_map[$post->post_title] = $post->ID;
    }

    // Get just the titles of the posts on the site.
    $site_titles = array_keys($site_titles_map);

    // Find the difference: titles on the site that are NOT in the spreadsheet.
    $titles_to_delete = array_diff($site_titles, $valid_finish_names);

    if (!empty($titles_to_delete)) {
        $ids_to_delete = [];
        foreach ($titles_to_delete as $title) {
            $ids_to_delete[] = $site_titles_map[$title];
        }

        // Break the deletion list into chunks and schedule them.
        $delete_chunks = array_chunk($ids_to_delete, 50);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_finish_options_chunk', ['post_ids' => $chunk], 'sm-variables-import');
        }
    }
}

/**
 * Background worker that deletes a chunk of posts.
 */
add_action('sm_delete_finish_options_chunk', 'sm_delete_finish_options_chunk_handler', 10, 1);
function sm_delete_finish_options_chunk_handler($post_ids_to_delete) {
    if (function_exists('WC')) {
        WC()->init();
    }
    
    foreach ($post_ids_to_delete as $post_id) {
        // The 'true' bypasses the trash and deletes the post permanently.
        wp_delete_post($post_id, true);
    }
}

// ==========================================================================
// SPECIES IMPORT LOGIC
// ==========================================================================

add_action('sm_process_species_chunk', 'sm_process_species_chunk_handler', 10, 3);
function sm_process_species_chunk_handler($data_file_path, $start_index, $count) {
    if (!file_exists($data_file_path)) return;
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $species_data = array_slice($all_rows, $start_index, $count);

    foreach ($species_data as $index => $row) {
        $menu_order = (int)$start_index + $index;
        $species_name = trim($row['Species']);
        if (empty($species_name)) continue;

        $post_id = null;
        $existing_post = get_page_by_title($species_name, OBJECT, 'species');
        if ($existing_post) {
            $post_id = $existing_post->ID;
            $update_args = ['ID' => $post_id, 'menu_order' => $menu_order];
            if ($existing_post->post_status === 'trash') $update_args['post_status'] = 'publish';
            wp_update_post($update_args);
        } else {
            $post_args = [
                'post_title'   => $species_name,
                'post_name'    => sanitize_title($species_name),
                'post_status'  => 'publish',
                'post_type'    => 'species',
                'menu_order'   => $menu_order,
            ];
            $post_id = wp_insert_post($post_args);
        }

        if ($post_id && !is_wp_error($post_id) && function_exists('update_field')) {
            
            // FATAL ERROR FIXED: Removed undefined $logger from the use() closure below
            $get_numeric_val = function($header_name) use ($row) {
                $val = isset($row[$header_name]) ? $row[$header_name] : null;
                $clean_val = str_replace(['%', '$', ','], '', (string)$val);
                return is_numeric($clean_val) ? floatval($clean_val) : 0;
            };

            update_field('price_at_thickness_1', $get_numeric_val("Thickness: 1.00 Charge"), $post_id);
            update_field('price_at_thickness_1.25', $get_numeric_val("Thickness: 1.25 Charge"), $post_id);
            update_field('price_at_thickness_1.5', $get_numeric_val("Thickness: 1.50 Charge"), $post_id);
            update_field('price_at_thickness_2', $get_numeric_val("Thickness: 2.00 Charge"), $post_id);
            update_field('price_at_thickness_2.5', $get_numeric_val("Thickness: 2.50 Charge"), $post_id);
            update_field('price_at_thickness_3', $get_numeric_val("Thickness: 3.00 Charge"), $post_id);
            update_field('price_at_thickness_4', $get_numeric_val("Thickness: 4.00 Charge"), $post_id);

            update_field('markup_7_16', $get_numeric_val("Length Markup: 7' - 16'"), $post_id);
            update_field('markup_7_8', $get_numeric_val("Length Markup: 7' - 8'"), $post_id);
            update_field('markup_9_10', $get_numeric_val("Length Markup: 9' - 10'"), $post_id);
            update_field('markup_8_10', $get_numeric_val("Length Markup: 8' - 10'"), $post_id);
            update_field('markup_12_14', $get_numeric_val("Length Markup: 12' - 14'"), $post_id);
            update_field('markup_15_16', $get_numeric_val("Length Markup: 15' - 16'"), $post_id);
            update_field('markup_94_only', $get_numeric_val('94" Only'), $post_id);
            update_field('markup_7_only', $get_numeric_val("7' Only"), $post_id);
            update_field('markup_8_only', $get_numeric_val("8' Only"), $post_id);
            update_field('markup_9_only', $get_numeric_val("9' Only"), $post_id);
            update_field('markup_10_only', $get_numeric_val("10' Only"), $post_id);

            update_field('wide_lumber_premium', $get_numeric_val('Wide Lumber Markup (%)'), $post_id);
            update_field('wide_lumber_threshold', $get_numeric_val('Wide Lumber Threshold (Decimal Number)'), $post_id);
        }
    }
}

// ==========================================================================
// SPECIES DELETION LOGIC
// ==========================================================================

add_action('sm_species_cleanup_gatekeeper', 'sm_species_cleanup_gatekeeper_handler', 10, 2);
function sm_species_cleanup_gatekeeper_handler($valid_ids, $data_file_path = '') {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_species_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'sm-species-import']);

    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 5, 'sm_species_cleanup_gatekeeper', ['valid_ids' => $valid_ids, 'data_file_path' => $data_file_path], 'sm-species-import');
        return;
    }

    if (!empty($data_file_path) && file_exists($data_file_path)) {
        @unlink($data_file_path);
    }

    as_schedule_single_action(time(), 'sm_initiate_species_deletion', ['valid_species_names' => $valid_ids], 'sm-species-import');
}

/**
 * Compares site species with the spreadsheet list and schedules deletions.
 */
add_action('sm_initiate_species_deletion', 'sm_initiate_species_deletion_handler', 10, 1);
// The handler was expecting a variable named $valid_ids, but the argument key from the gatekeeper
// was incorrect. We now correctly name the parameter to match what we are sending.
function sm_initiate_species_deletion_handler($valid_species_names) {
    $all_site_posts = get_posts(['post_type' => 'species', 'post_status' => 'any', 'posts_per_page' => -1]);
    $site_titles_map = [];
    foreach ($all_site_posts as $post) {
        $site_titles_map[$post->post_title] = $post->ID;
    }
    $site_titles = array_keys($site_titles_map);

    // Now this comparison will work correctly.
    $titles_to_delete = array_diff($site_titles, $valid_species_names);

    if (!empty($titles_to_delete)) {
        $ids_to_delete = [];
        foreach ($titles_to_delete as $title) {
            $ids_to_delete[] = $site_titles_map[$title];
        }
        $delete_chunks = array_chunk($ids_to_delete, 50);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_species_chunk', ['post_ids' => $chunk], 'sm-species-import');
        }
    }
}

/**
 * Background worker that deletes a chunk of species posts.
 */
add_action('sm_delete_species_chunk', 'sm_delete_species_chunk_handler', 10, 1);
function sm_delete_species_chunk_handler($post_ids_to_delete) {
    if (function_exists('WC')) {
        WC()->init();
    }

    foreach ($post_ids_to_delete as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// ==========================================================================
// SHIPPING ZONE IMPORT LOGIC (REWRITTEN FOR RELIABILITY)
// ==========================================================================

/**
 * Background job to process a chunk of UNIQUE Shipping Zones.
 * This new structure finds and deletes ALL duplicate zones before rebuilding.
 */
add_action('sm_process_shipping_chunk', 'sm_process_shipping_chunk_handler', 10, 2);
function sm_process_shipping_chunk_handler($ids_to_process, $data_file_path) {
    if (!class_exists('WC_Shipping_Zones') || !file_exists($data_file_path)) {
        return;
    }

    $grouped_data = json_decode(file_get_contents($data_file_path), true);
    if (empty($grouped_data)) {
        return;
    }

    $all_sample_methods = [];
    if (isset($grouped_data['all_sample_methods'])) {
        $all_sample_methods = $grouped_data['all_sample_methods'];
    }

    foreach ($ids_to_process as $zone_name) {
        if (empty($zone_name) || !isset($grouped_data[$zone_name])) {
            continue;
        }

        // --- THIS IS THE OVERWRITE FIX ---
        // 1. Find if a zone with this name already exists.
        $all_zones = WC_Shipping_Zones::get_zones();
        $existing_zone_id = null;
        
        if (!empty($all_zones)) {
            foreach ($all_zones as $zone_data) {
                if (isset($zone_data['zone_name']) && $zone_data['zone_name'] === $zone_name) {
                    if ($existing_zone_id === null) {
                        $existing_zone_id = $zone_data['id']; // Keep the first matching zone
                    } else {
                        WC_Shipping_Zones::delete_zone($zone_data['id']); // Delete accidental duplicates
                    }
                }
            }
        }

        if ($existing_zone_id) {
            // 2A. OVERWRITE EXISTING: Load it and wipe its data clean so we can inject the fresh spreadsheet data
            $zone = new WC_Shipping_Zone($existing_zone_id);
            $zone->clear_locations();
            
            $methods = $zone->get_shipping_methods();
            foreach ($methods as $instance_id => $method) {
                $zone->delete_shipping_method($instance_id);
            }
        } else {
            // 2B. CREATE NEW: Only if it doesn't exist at all
            $new_zone = new WC_Shipping_Zone();
            $new_zone->set_zone_name($zone_name);
            $new_zone->save();
            $zone = new WC_Shipping_Zone($new_zone->get_id());
        }
        // --- END OVERWRITE FIX ---
        
        if ($zone) {
            $rows_for_this_zone = $grouped_data[$zone_name];
            $first_row = $rows_for_this_zone[0];

            // Because this is a brand new zone, we don't need to clear anything.
            // We just add the correct data.

            // ADD all locations from all matching rows in the spreadsheet.
            foreach ($rows_for_this_zone as $row) {
                $state_code = isset($row['State']) ? trim($row['State']) : '';
                $zip_codes_raw = isset($row['Zipcodes']) ? trim($row['Zipcodes']) : '';
                if (!empty($state_code)) {
                    $zone->add_location('US:' . $state_code, 'state');
                }
                if (!empty($zip_codes_raw)) {
                    $zip_codes = array_map('trim', explode(',', $zip_codes_raw));
                    foreach ($zip_codes as $zip) {
                        if (!empty($zip)) $zone->add_location($zip, 'postcode');
                    }
                }
            }
            
            // ADD the "Curbside Delivery" method.
            $curbside_price = isset($first_row['Price']) ? trim($first_row['Price']) : '0';
            $instance_id = $zone->add_shipping_method('flat_rate');
            if ($instance_id) {
                $method = WC_Shipping_Zones::get_shipping_method($instance_id);
                if ($method) {
                    $settings = $method->instance_settings;
                    $settings['title'] = 'Curbside Delivery';
                    $settings['cost'] = $curbside_price;
                    update_option($method->get_instance_option_key(), $settings);
                }
            }

            // ADD all "Sample Shipping" methods.
            if (!empty($all_sample_methods)) {
                foreach ($all_sample_methods as $sample_method) {
                    $instance_id_sample = $zone->add_shipping_method('flat_rate');
                    if ($instance_id_sample) {
                        $method_sample = WC_Shipping_Zones::get_shipping_method($instance_id_sample);
                        if ($method_sample) {
                            $settings = $method_sample->instance_settings;
                            $settings['title'] = $sample_method['title'];
                            $settings['cost'] = $sample_method['price'];
                            update_option($method_sample->get_instance_option_key(), $settings);
                        }
                    }
                }
            }
            
            // SAVE the newly built zone.
            $zone->save();
        }
    }
    
    // Clean up the temp file if this is the last job.
    $pending_actions = as_get_scheduled_actions([
        'hook'   => 'sm_process_shipping_chunk',
        'status' => ActionScheduler_Store::STATUS_PENDING,
        'group'  => 'sm-shipping-import',
    ]);
    if (empty($pending_actions)) {
        @unlink($data_file_path);
    }
}


// ==========================================================================
// SHIPPING ZONE DELETION LOGIC
// ==========================================================================

/**
 * Gatekeeper function for shipping zone deletion.
 */
add_action('sm_shipping_cleanup_gatekeeper', 'sm_shipping_cleanup_gatekeeper_handler', 10, 2);
function sm_shipping_cleanup_gatekeeper_handler($valid_ids, $sample_methods) {
    $pending_actions = as_get_scheduled_actions([
        'hook'   => 'sm_process_shipping_chunk',
        'status' => ActionScheduler_Store::STATUS_PENDING,
        'group'  => 'sm-shipping-import',
    ]);

    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 30, 'sm_shipping_cleanup_gatekeeper', ['valid_ids' => $valid_ids, 'sample_methods' => $sample_methods], 'sm-shipping-import');
        return;
    }
    as_schedule_single_action(time(), 'sm_initiate_shipping_deletion', ['valid_zone_names' => $valid_ids, 'sample_methods' => $sample_methods], 'sm-shipping-import');
}


/**
 * Compares site zones, schedules deletions, and configures the "USA & Canada" catch-all zone.
 */
add_action('sm_initiate_shipping_deletion', 'sm_initiate_shipping_deletion_handler', 10, 2);
function sm_initiate_shipping_deletion_handler($valid_zone_names, $sample_methods) {
    if (!function_exists('WC')) return;

    // --- (Existing logic to delete old zones from the spreadsheet is unchanged) ---
    $all_zones = WC_Shipping_Zones::get_zones();
    $site_zone_names = [];
    $site_zone_map = [];
    foreach ($all_zones as $zone_data) {
        $zone = new WC_Shipping_Zone($zone_data['id']);
        $site_zone_names[] = $zone->get_zone_name();
        $site_zone_map[$zone->get_zone_name()] = $zone->get_id();
    }
    $zones_to_delete = array_diff($site_zone_names, $valid_zone_names);
    // Don't delete our new catch-all zone if it already exists
    if (($key = array_search('USA', $zones_to_delete)) !== false) {
        unset($zones_to_delete[$key]);
    }
    if (!empty($zones_to_delete)) {
        $ids_to_delete = [];
        foreach ($zones_to_delete as $zone_name) {
            $ids_to_delete[] = $site_zone_map[$zone_name];
        }
        $delete_chunks = array_chunk($ids_to_delete, 5);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_shipping_chunk', ['zone_ids' => $chunk], 'sm-shipping-import');
        }
    }

    // --- NEW: CONFIGURE THE "USA" CATCH-ALL ZONE (OVERWRITE MODE) ---

    // 1. Find if the "USA" zone already exists.
    $all_zones_for_cleanup = WC_Shipping_Zones::get_zones();
    $usa_zone_id = null;
    
    if (!empty($all_zones_for_cleanup)) {
        foreach ($all_zones_for_cleanup as $zone_data) {
            if (isset($zone_data['zone_name']) && $zone_data['zone_name'] === 'USA') {
                if ($usa_zone_id === null) {
                    $usa_zone_id = $zone_data['id']; // Keep the first matching zone
                } else {
                    WC_Shipping_Zones::delete_zone($zone_data['id']); // Clean up duplicates
                }
            }
        }
    }

    if ($usa_zone_id) {
        // 2A. OVERWRITE EXISTING: Wipe its old methods and locations
        $zone = new WC_Shipping_Zone($usa_zone_id);
        $zone->clear_locations();
        $methods = $zone->get_shipping_methods();
        foreach ($methods as $instance_id => $method) {
            $zone->delete_shipping_method($instance_id);
        }
        $zone->set_zone_order(9999);
    } else {
        // 2B. CREATE NEW: If it doesn't exist, create it and force it to the bottom
        $new_zone = new WC_Shipping_Zone();
        $new_zone->set_zone_name('USA');
        $new_zone->set_zone_order(9999); // Force catch-all to the very bottom priority
        $new_zone->save();
        $zone = new WC_Shipping_Zone($new_zone->get_id());
    }
    // --- END OVERWRITE FIX ---

    if ($zone) {
        // 3. Set its locations.
        $zone->add_location('US', 'country');

        // 4. Add ONLY the sample shipping methods. (No need to wipe methods, it's a new zone)
        if (!empty($sample_methods)) {
            foreach ($sample_methods as $sample_method) {
                $instance_id = $zone->add_shipping_method('flat_rate');
                if ($instance_id) {
                    $method = WC_Shipping_Zones::get_shipping_method($instance_id);
                    if ($method) {
                        $settings = $method->instance_settings;
                        $settings['title'] = $sample_method['title'];
                        $settings['cost'] = $sample_method['price'];
                        update_option($method->get_instance_option_key(), $settings);
                    }
                }
            }
        }
        
        // ADD THE "LTL Freight" METHOD.
        $instance_id_ltl = $zone->add_shipping_method('flat_rate');
        if ($instance_id_ltl) {
            $method_ltl = WC_Shipping_Zones::get_shipping_method($instance_id_ltl);
            if ($method_ltl) {
                $settings = $method_ltl->instance_settings;
                $settings['title'] = 'LTL Shipping';
                $settings['cost'] = '0'; // Set the default cost to 0
                update_option($method_ltl->get_instance_option_key(), $settings);
            }
        }

        // 5. Save the final zone configuration.
        $zone->save();
    }
}

/**
 * Background worker that deletes a chunk of shipping zones.
 */
add_action('sm_delete_shipping_chunk', 'sm_delete_shipping_chunk_handler', 10, 1);
function sm_delete_shipping_chunk_handler($zone_ids) {
    if (function_exists('WC')) {
        WC()->init();
    }

    foreach ($zone_ids as $zone_id) {
        WC_Shipping_Zones::delete_zone($zone_id);
    }
}

// ==========================================================================
// STAIN COLORS IMPORT LOGIC
// ==========================================================================

/**
 * Background job to process a chunk of "Stain Colors" posts.
 * This function creates or updates the 'stain_color' custom post type.
 */
add_action('sm_process_stain_colors_chunk', 'sm_process_stain_colors_chunk_handler', 10, 3);
function sm_process_stain_colors_chunk_handler($data_file_path, $start_index, $count) {
    if (!file_exists($data_file_path)) return;
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $data = array_slice($all_rows, $start_index, $count);
    foreach ($data as $index => $row) {
        $menu_order = (int)$start_index + $index;
        $post_title = trim($row['Stain Colors']);
        if (empty($post_title)) continue;

        $existing_post = get_page_by_title($post_title, OBJECT, 'stain');
        if ($existing_post) {
            $update_args = ['ID' => $existing_post->ID, 'menu_order' => $menu_order];
            if ($existing_post->post_status === 'trash') $update_args['post_status'] = 'publish';
            wp_update_post($update_args);
        } else {
            wp_insert_post(['post_title' => $post_title, 'post_name' => sanitize_title($post_title), 'post_status' => 'publish', 'post_type' => 'stain', 'menu_order' => $menu_order]);
        }
    }
}

// ==========================================================================
// STAIN COLORS DELETION LOGIC
// ==========================================================================

/**
 * Gatekeeper function for stain colors deletion.
 */
add_action('sm_stain_colors_cleanup_gatekeeper', 'sm_stain_colors_cleanup_gatekeeper_handler', 10, 2);
function sm_stain_colors_cleanup_gatekeeper_handler($valid_ids, $data_file_path = '') {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_stain_colors_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'sm-stain-colors-import']);
    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 5, 'sm_stain_colors_cleanup_gatekeeper', ['valid_ids' => $valid_ids, 'data_file_path' => $data_file_path], 'sm-stain-colors-import');
        return;
    }
    if (!empty($data_file_path) && file_exists($data_file_path)) { @unlink($data_file_path); }
    as_schedule_single_action(time(), 'sm_initiate_stain_colors_deletion', ['valid_names' => $valid_ids], 'sm-stain-colors-import');
}

/**
 * Compares site posts with the spreadsheet list and schedules deletions.
 */
add_action('sm_initiate_stain_colors_deletion', 'sm_initiate_stain_colors_deletion_handler', 10, 1);
function sm_initiate_stain_colors_deletion_handler($valid_names) {
    $all_site_posts = get_posts(['post_type' => 'stain', 'post_status' => 'any', 'posts_per_page' => -1]);
    $site_titles_map = [];
    foreach ($all_site_posts as $post) {
        $site_titles_map[$post->post_title] = $post->ID;
    }
    $site_titles = array_keys($site_titles_map);
    $titles_to_delete = array_diff($site_titles, $valid_names);

    if (!empty($titles_to_delete)) {
        $ids_to_delete = [];
        foreach ($titles_to_delete as $title) {
            $ids_to_delete[] = $site_titles_map[$title];
        }
        $delete_chunks = array_chunk($ids_to_delete, 10);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_stain_colors_chunk', ['post_ids' => $chunk], 'sm-stain-colors-import');
        }
    }
}

/**
 * Background worker that deletes a chunk of stain color posts.
 */
add_action('sm_delete_stain_colors_chunk', 'sm_delete_stain_colors_chunk_handler', 10, 1);
function sm_delete_stain_colors_chunk_handler($post_ids) {
    if (function_exists('WC')) {
        WC()->init();
    }

    foreach ($post_ids as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// ==========================================================================
// SHEEN LEVELS IMPORT LOGIC
// ==========================================================================

/**
 * Background job to process a chunk of "Sheen Levels" posts.
 * This function creates or updates the 'sheen' custom post type.
 */
add_action('sm_process_sheen_levels_chunk', 'sm_process_sheen_levels_chunk_handler', 10, 3);
function sm_process_sheen_levels_chunk_handler($data_file_path, $start_index, $count) {
    if (!file_exists($data_file_path)) return;
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $data = array_slice($all_rows, $start_index, $count);
    foreach ($data as $index => $row) {
        $menu_order = (int)$start_index + $index;
        $post_title = trim($row['Sheen Levels']);
        if (empty($post_title)) continue;

        $existing_post = get_page_by_title($post_title, OBJECT, 'sheen');
        if ($existing_post) {
            $update_args = ['ID' => $existing_post->ID, 'menu_order' => $menu_order];
            if ($existing_post->post_status === 'trash') $update_args['post_status'] = 'publish';
            wp_update_post($update_args);
        } else {
            wp_insert_post(['post_title' => $post_title, 'post_name' => sanitize_title($post_title), 'post_status' => 'publish', 'post_type' => 'sheen', 'menu_order' => $menu_order]);
        }
    }
}

// ==========================================================================
// SHEEN LEVELS DELETION LOGIC
// ==========================================================================

/**
 * Gatekeeper function for sheen levels deletion.
 */
add_action('sm_sheen_levels_cleanup_gatekeeper', 'sm_sheen_levels_cleanup_gatekeeper_handler', 10, 2);
function sm_sheen_levels_cleanup_gatekeeper_handler($valid_ids, $data_file_path = '') {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_sheen_levels_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'sm-sheen-levels-import']);
    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 5, 'sm_sheen_levels_cleanup_gatekeeper', ['valid_ids' => $valid_ids, 'data_file_path' => $data_file_path], 'sm-sheen-levels-import');
        return;
    }
    if (!empty($data_file_path) && file_exists($data_file_path)) { @unlink($data_file_path); }
    as_schedule_single_action(time(), 'sm_initiate_sheen_levels_deletion', ['valid_names' => $valid_ids], 'sm-sheen-levels-import');
}

/**
 * Compares site posts with the spreadsheet list and schedules deletions.
 */
add_action('sm_initiate_sheen_levels_deletion', 'sm_initiate_sheen_levels_deletion_handler', 10, 1);
function sm_initiate_sheen_levels_deletion_handler($valid_names) {
    $all_site_posts = get_posts(['post_type' => 'sheen', 'post_status' => 'any', 'posts_per_page' => -1]);
    $site_titles_map = [];
    foreach ($all_site_posts as $post) {
        $site_titles_map[$post->post_title] = $post->ID;
    }
    $site_titles = array_keys($site_titles_map);
    $titles_to_delete = array_diff($site_titles, $valid_names);

    if (!empty($titles_to_delete)) {
        $ids_to_delete = [];
        foreach ($titles_to_delete as $title) {
            $ids_to_delete[] = $site_titles_map[$title];
        }
        $delete_chunks = array_chunk($ids_to_delete, 10);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_sheen_levels_chunk', ['post_ids' => $chunk], 'sm-sheen-levels-import');
        }
    }
}

/**
 * Background worker that deletes a chunk of sheen level posts.
 */
add_action('sm_delete_sheen_levels_chunk', 'sm_delete_sheen_levels_chunk_handler', 10, 1);
function sm_delete_sheen_levels_chunk_handler($post_ids) {
    if (function_exists('WC')) {
        WC()->init();
    }

    foreach ($post_ids as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// ==========================================================================
// LENGTHS IMPORT LOGIC
// ==========================================================================

/**
 * Background job to process a chunk of "Lengths" posts.
 * This function creates or updates the 'lengths' custom post type.
 */
add_action('sm_process_lengths_chunk', 'sm_process_lengths_chunk_handler', 10, 3);
function sm_process_lengths_chunk_handler($data_file_path, $start_index, $count) {
    if (!file_exists($data_file_path)) return;
    $all_rows = json_decode(file_get_contents($data_file_path), true);
    if (!is_array($all_rows)) return;
    
    $data = array_slice($all_rows, $start_index, $count);
    foreach ($data as $index => $row) {
        $menu_order = (int)$start_index + $index;
        $post_title = trim($row['Lengths']);
        if (empty($post_title)) continue;

        $existing_post = get_page_by_title($post_title, OBJECT, 'lengths');
        if ($existing_post) {
            $update_args = ['ID' => $existing_post->ID, 'menu_order' => $menu_order];
            if ($existing_post->post_status === 'trash') $update_args['post_status'] = 'publish';
            wp_update_post($update_args);
        } else {
            wp_insert_post(['post_title' => $post_title, 'post_name' => sanitize_title($post_title), 'post_status' => 'publish', 'post_type' => 'lengths', 'menu_order' => $menu_order]);
        }
    }
}

// ==========================================================================
// LENGTHS DELETION LOGIC
// ==========================================================================

/**
 * Gatekeeper function for lengths deletion.
 */
add_action('sm_lengths_cleanup_gatekeeper', 'sm_lengths_cleanup_gatekeeper_handler', 10, 2);
function sm_lengths_cleanup_gatekeeper_handler($valid_ids, $data_file_path = '') {
    $pending_actions = as_get_scheduled_actions(['hook' => 'sm_process_lengths_chunk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'group' => 'sm-lengths-import']);
    if (!empty($pending_actions)) {
        as_schedule_single_action(time() + 5, 'sm_lengths_cleanup_gatekeeper', ['valid_ids' => $valid_ids, 'data_file_path' => $data_file_path], 'sm-lengths-import');
        return;
    }
    if (!empty($data_file_path) && file_exists($data_file_path)) { @unlink($data_file_path); }
    as_schedule_single_action(time(), 'sm_initiate_lengths_deletion', ['valid_names' => $valid_ids], 'sm-lengths-import');
}

/**
 * Compares site posts with the spreadsheet list and schedules deletions.
 */
add_action('sm_initiate_lengths_deletion', 'sm_initiate_lengths_deletion_handler', 10, 1);
function sm_initiate_lengths_deletion_handler($valid_names) {
    $all_site_posts = get_posts(['post_type' => 'lengths', 'post_status' => 'any', 'posts_per_page' => -1]);
    $site_titles_map = [];
    foreach ($all_site_posts as $post) {
        $site_titles_map[$post->post_title] = $post->ID;
    }
    $site_titles = array_keys($site_titles_map);
    $titles_to_delete = array_diff($site_titles, $valid_names);

    if (!empty($titles_to_delete)) {
        $ids_to_delete = [];
        foreach ($titles_to_delete as $title) {
            $ids_to_delete[] = $site_titles_map[$title];
        }
        $delete_chunks = array_chunk($ids_to_delete, 10);
        foreach ($delete_chunks as $chunk) {
            as_schedule_single_action(time(), 'sm_delete_lengths_chunk', ['post_ids' => $chunk], 'sm-lengths-import');
        }
    }
}

/**
 * Background worker that deletes a chunk of lengths posts.
 */
add_action('sm_delete_lengths_chunk', 'sm_delete_lengths_chunk_handler', 10, 1);
function sm_delete_lengths_chunk_handler($post_ids) {
    if (function_exists('WC')) {
        WC()->init();
    }

    foreach ($post_ids as $post_id) {
        wp_delete_post($post_id, true);
    }
}

/**
 * ==========================================================================
 * STARKE COMMERCE OPTIONS IMPORT LOGIC
 * ==========================================================================
 *
 * Reads data from the "Price Formula" and "Shipping" worksheets in
 * Variables.xlsx to automatically update the 'starke_commerce_options'.
 *
 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet The loaded spreadsheet object.
 */
function sm_update_starke_commerce_options($spreadsheet) {
    $options_to_save = [];

    // --- Process "Price Formula" Worksheet ---
    $price_formula_sheet = $spreadsheet->getSheetByName('Price Formula');
    if ($price_formula_sheet) {
        
        // Helper function to get a numeric value from a specific cell
        $get_cell_value = function($cell) use ($price_formula_sheet) {
            $val = $price_formula_sheet->getCell($cell)->getCalculatedValue();
            return is_numeric($val) ? floatval($val) : 0;
        };

        // Map spreadsheet cells to option keys
        $cell_map = [
            'minimum_cost_per_linear_foot'            => 'B10',
            'upcharge_for_runs_under_100_linear_feet' => 'B44',
            'ripping_cost_per_linear_foot'            => 'E16',
            'machining_cost_per_linear_foot'          => 'E17',
            'charge_per_sample'                       => 'B65',
            'rabbet_cost_per_linear_foot_100up'       => 'B42',
            'relief_angle_cost_per_linear_foot_100up' => 'B43',
            'rabbet_under100ft_setup_charge'          => 'B45',
            'relief_angle_under100ft_setup_charge'    => 'B46',
            'card_convenience_fee'                    => 'B66',
            'quote_duration'                          => 'B67',
        ];

        foreach ($cell_map as $option_key => $cell) {
            $value = $get_cell_value($cell); // Get the raw numeric value

            if ($option_key === 'card_convenience_fee') {
                // Convert the decimal from the spreadsheet (e.g., 0.03) to a percentage (e.g., 3)
                $options_to_save[$option_key] = (string)($value * 100);
            } else if ($option_key === 'quote_duration') {
                // Ensure the quote duration is saved as a whole number (integer)
                $options_to_save[$option_key] = (string)intval($value);
            } else {
                // For all other fields, format as currency with 2 decimal places
                $options_to_save[$option_key] = number_format($value, 2, '.', '');
            }
        }

        // Process the Discounts repeater field
        $discounts = [];
        $start_row = 49; // The first row of discounts data
        $end_row = 60;   // The last allowed row of discounts data
        
        // Loop through the specified range for discounts
        for ($current_row = $start_row; $current_row <= $end_row; $current_row++) {
            $amount_val = $price_formula_sheet->getCell('A' . $current_row)->getCalculatedValue();
            $percentage_val = $price_formula_sheet->getCell('B' . $current_row)->getCalculatedValue();

            // Skip if the row is empty to avoid processing blank rows within the range
            if (empty($amount_val) && empty($percentage_val)) {
                continue;
            }

            if (is_numeric($amount_val) && is_numeric($percentage_val)) {
                $discounts[] = [
                    'amount'     => (string)$amount_val,
                    // Multiply the decimal value by 100 to get the correct percentage
                    'percentage' => (string)($percentage_val * 100),
                ];
            }
        }
        $options_to_save['discounts'] = $discounts;
    }

    // --- Process "Shipping" Worksheet ---
    $shipping_sheet = $spreadsheet->getSheetByName('Shipping');
    if ($shipping_sheet) {
        // Get Standard Shipping from cell F2
        $standard_price = $shipping_sheet->getCell('F2')->getCalculatedValue();
        if (is_numeric($standard_price)) {
            $options_to_save['samples_standard_shipping_cost'] = number_format(floatval($standard_price), 2, '.', '');
        }

        // Get Express Shipping from cell F3
        $express_price = $shipping_sheet->getCell('F3')->getCalculatedValue();
        if (is_numeric($express_price)) {
            $options_to_save['samples_express_shipping_cost'] = number_format(floatval($express_price), 2, '.', '');
        }
    }

    // Update the option in the database
    if (!empty($options_to_save)) {
        update_option('starke_commerce_options', $options_to_save);
    }
}

/**
 * ==========================================================================
 * IMPORT COMPLETION NOTIFICATION
 * ==========================================================================
 */

add_action('sm_process_import_completion_email_async', 'sm_process_import_completion_email_async_handler', 10, 1);
function sm_process_import_completion_email_async_handler( $import_name ) {
    // 1. Get all administrator emails.
    $admins = get_users( 'role=administrator' );
    $admin_emails = array();
    foreach ( $admins as $admin ) {
        $admin_emails[] = $admin->user_email;
    }

    // 2. Define the list of admin emails to exclude (if any).
    $excluded_emails = []; // ['danielle@starkemillwork.com', 'zac@starkemillwork.com', 'gretchen@starkemillwork.com']

    // 3. Remove the excluded emails from the recipient list.
    $recipient_emails = array_diff( $admin_emails, $excluded_emails );

    if ( empty( $recipient_emails ) ) {
        return; // No recipients left, so stop.
    }

    $subject = sprintf( 'Import Job Complete' );
    $heading = 'Import Process Finished';
    $products_link = admin_url( 'edit.php?post_type=product' );

    // 4. Construct the Body HTML
    ob_start();
    ?>
    <div style="font-size: 18px !important; line-height: 1.5; color: #636363; margin-bottom: 20px;">
        The background import process for the <strong><?php echo esc_html( $import_name ); ?></strong> has successfully completed all scheduled tasks and updates.
    </div>

    <div style="text-align: center; margin-top: 30px;">
        <a href="<?php echo esc_url( $products_link ); ?>" style="color: #6431F6; font-size: 18px; font-weight: bold; text-decoration: underline;">
            View Product Catalog
        </a>
    </div>
    <?php
    $content = ob_get_clean();

    // 5. Load Standard WC Templates
    $header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading ) );
    $footer = wc_get_template_html( 'emails/email-footer.php' );
    
    $final_message = $header . $content . $footer;

    // 6. Apply Inline Styles
    try {
        if (function_exists('WC')) {
            $mailer = WC()->mailer();
            $email_object = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null;

            if ( $email_object && method_exists( $email_object, 'style_inline' ) ) {
                $final_message = $email_object->style_inline( $final_message );
            }
        }
    } catch ( Exception $e ) {
        if ( function_exists('wc_get_logger') ) {
            wc_get_logger()->warning('Import Email Style Error: ' . $e->getMessage());
        }
    }

    // 7. Send the email (Using an anonymous function for the filter outside of a class)
    $set_html_content_type = function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $set_html_content_type );
    
    wp_mail( $recipient_emails, $subject, $final_message );
    
    remove_filter( 'wp_mail_content_type', $set_html_content_type );
}

/**
 * ==========================================================================
 * GLOBAL IMPORT COMPLETION MONITOR (UNIFIED BATCH VERSION)
 * ==========================================================================
 */

/**
 * HELPER: Start the monitor. 
 * This ensures only ONE monitor runs, even if 5 endpoints are hit at once.
 */
function sm_start_import_monitor() {
    // 1. Set a lock so the system knows an import batch is currently active
    set_transient('sm_import_batch_active', true, DAY_IN_SECONDS);

    // 2. Only spawn the background monitor if one isn't already watching
    if ( ! as_has_scheduled_action('sm_global_import_completion_monitor') ) {
        as_schedule_single_action(time() + 10, 'sm_global_import_completion_monitor', [], 'sm-import-monitor');
    }
}

add_action('sm_global_import_completion_monitor', 'sm_global_import_completion_monitor_handler');
function sm_global_import_completion_monitor_handler() {
    $groups_to_check = [
        'sm-product-import', 'sm-media-import', 'sm-inventory-import',
        'sm-variables-import', 'sm-species-import', 'sm-stain-colors-import',
        'sm-sheen-levels-import', 'sm-lengths-import', 'sm-shipping-import'
    ];

    $is_busy = false;
    foreach ($groups_to_check as $group) {
        $pending = as_get_scheduled_actions(['group' => $group, 'status' => ActionScheduler_Store::STATUS_PENDING]);
        $running = as_get_scheduled_actions(['group' => $group, 'status' => ActionScheduler_Store::STATUS_RUNNING]);
        
        if (!empty($pending) || !empty($running)) {
            $is_busy = true;
            break; 
        }
    }

    if ($is_busy) {
        // Jobs are still processing. Reschedule to check again in 15 seconds.
        as_schedule_single_action(time() + 15, 'sm_global_import_completion_monitor', [], 'sm-import-monitor');
    } else {
        // All queues are empty! Check if we actually had an active batch.
        if ( get_transient('sm_import_batch_active') ) {
            // Delete the transient immediately so it can't be triggered again
            delete_transient('sm_import_batch_active'); 
            
            // =========================================================
            // --- NATIVE GLOBAL CACHE SWEEP (RUNS ONLY ONCE) ---
            // =========================================================
            // 1. Clear WP Grid Builder Render Caches safely
            if ( class_exists( '\WP_Grid_Builder\Includes\Caches' ) ) {
                \WP_Grid_Builder\Includes\Caches::get_instance()->clear_render_cache();
            }

            // --- REBUILD 3D PROFILE CACHE ONCE AT THE END OF THE IMPORT ---
            if ( function_exists('starke_rebuild_profile_list_cache') ) {
                starke_rebuild_profile_list_cache();
            }

            // Fire ONE final unified email
            as_schedule_single_action(time(), 'sm_process_import_completion_email_async', ['import_name' => '3D Profile & Website Automation System'], 'sm-import-monitor');
        }
    }
}