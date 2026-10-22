<?php
defined( 'ABSPATH' ) || exit;

\EntryVault\Admin_UI::open(
    __( 'Board', 'entryvault' ),
    __( 'Every form submission as a card. Drag a card to move it to the next stage, or open it to reply, add notes and tags.', 'entryvault' ),
    '',
    [ 'wide' => true ]
);
?>
<div id="entryvault-kanban-root" class="ev-board-root">
    <div class="ev-board-loading">
        <span class="jnkui-spin" aria-hidden="true"></span>
        <?php esc_html_e( 'Loading the board…', 'entryvault' ); ?>
        <p class="jnkui-muted">
            <?php esc_html_e( 'If this message stays, the board files are missing. Run', 'entryvault' ); ?>
            <code>npm install &amp;&amp; npm run build</code>
            <?php esc_html_e( 'in the plugin folder.', 'entryvault' ); ?>
        </p>
    </div>
</div>
<?php
\EntryVault\Admin_UI::close();
