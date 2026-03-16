<div class="wrap">
    <h1><?php _e( 'Generation Results', 'aebg' ); ?></h1>
    <?php
    global $wpdb;
    $batch_id = (int) $_GET['batch_id'];
    $batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $batch_id ) );
    $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aebg_batch_items WHERE batch_id = %d", $batch_id ) );
    ?>
    <h2><?php printf( __( 'Batch #%d', 'aebg' ), $batch_id ); ?></h2>
    <p>
        <strong><?php _e( 'Status:', 'aebg' ); ?></strong> <?php echo esc_html( $batch->status ); ?><br>
        <strong><?php _e( 'Total Items:', 'aebg' ); ?></strong> <?php echo esc_html( $batch->total_items ); ?><br>
        <strong><?php _e( 'Processed Items:', 'aebg' ); ?></strong> <?php echo esc_html( $batch->processed_items ); ?><br>
        <strong><?php _e( 'Failed Items:', 'aebg' ); ?></strong> <?php echo esc_html( $batch->failed_items ); ?>
    </p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e( 'Source Title', 'aebg' ); ?></th>
                <th><?php _e( 'Generated Post', 'aebg' ); ?></th>
                <th><?php _e( 'Status', 'aebg' ); ?></th>
                <th><?php _e( 'Log Message', 'aebg' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $items as $item ) : ?>
                <tr>
                    <td><?php echo esc_html( $item->source_title ); ?></td>
                    <td>
                        <?php if ( $item->generated_post_id ) : ?>
                            <a href="<?php echo get_edit_post_link( $item->generated_post_id ); ?>"><?php echo get_the_title( $item->generated_post_id ); ?></a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $item->status ); ?></td>
                    <td><?php echo esc_html( $item->log_message ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
