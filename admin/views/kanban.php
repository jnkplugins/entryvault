<?php
defined( 'ABSPATH' ) || exit;

\EntryVault\Admin_UI::open(
    __( 'Board', 'entryvault' ),
    __( 'Every form submission as a card. Drag a card to move it to the next stage, or open it to reply, add notes and tags.', 'entryvault' ),
    '',
    [ 'wide' => true ]
);

// Entries a form plugin marked as spam are left out by default. Say how many, so a
// real message caught by mistake can still be found.
$ev_skipped = \EntryVault\Adapters\Adapter_Base::skip_spam_enabled()
    ? \EntryVault\Adapters\Adapter_Base::spam_skips( time() - 30 * DAY_IN_SECONDS )
    : [];
// Counted apart from the list, which keeps only the latest 100.
$ev_skipped_n = $ev_skipped ? \EntryVault\Adapters\Adapter_Base::spam_skip_count( time() - 30 * DAY_IN_SECONDS ) : 0;
if ( $ev_skipped ) :
    $ev_from_tf = (bool) array_filter( $ev_skipped, static fn( $s ) => 'trinity-forms' === ( $s['source'] ?? '' ) );
    ?>
    <div class="jnkui-callout ev-spam-skipped"><p>
        <?php
        echo esc_html( sprintf(
            /* translators: 1: number of entries, 2: date of the latest one. */
            _n(
                '%1$d entry in the last 30 days was left out because its form plugin marked it as spam (latest %2$s). Spam filters are sometimes wrong, so it is worth a look.',
                '%1$d entries in the last 30 days were left out because their form plugin marked them as spam (latest %2$s). Spam filters are sometimes wrong, so they are worth a look.',
                $ev_skipped_n,
                'entryvault'
            ),
            $ev_skipped_n,
            wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ev_skipped[0]['at'] )
        ) );
        ?>
        <?php if ( $ev_from_tf ) : ?>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=trinity_forms_form&page=trinity-forms-entries&view=spam' ) ); ?>"><?php esc_html_e( 'Review them in Trinity Forms', 'entryvault' ); ?></a>
            <?php esc_html_e( '(an entry you restore there comes onto this board).', 'entryvault' ); ?>
        <?php endif; ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=entryvault-settings' ) ); ?>"><?php esc_html_e( 'Keep spam entries in EntryVault', 'entryvault' ); ?></a>
    </p></div>
<?php endif; ?>
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
