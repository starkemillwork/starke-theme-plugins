<?php
/**
 * Plugin Name: Force Disable Action Scheduler Async Runner
 * Description: Forcefully disables the Action Scheduler's async request runner to ensure all tasks are handled by WP-CLI.
 */

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );