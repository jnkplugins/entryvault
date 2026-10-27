import { useDraggable } from "@dnd-kit/core";
import { __, _x, sprintf } from "@wordpress/i18n";
import { utcMs, formatSiteTime } from "./siteTime";

function relativeAge( iso ) {
    if ( ! iso ) return "";
    const diffMs = Date.now() - utcMs( iso );
    const m = Math.floor( diffMs / 60000 );
    if ( m < 1 ) return __( "just now", "entryvault" );
    /* translators: %d: number of minutes, shown in a short card corner. */
    if ( m < 60 ) return sprintf( __( "%dm", "entryvault" ), m );
    const h = Math.floor( m / 60 );
    /* translators: %d: number of hours, shown in a short card corner. */
    if ( h < 24 ) return sprintf( __( "%dh", "entryvault" ), h );
    const d = Math.floor( h / 24 );
    /* translators: %d: number of days, shown in a short card corner. */
    return sprintf( __( "%dd", "entryvault" ), d );
}

/** The owner's name, for the avatar tooltip. Falls back to the id only if the
 *  boot payload genuinely lacks the user (deleted account, say). */
function ownerLabel( card ) {
    const name = ownerName( card );
    /* translators: %s: owner name, or #user id when the user no longer exists. */
    return sprintf( __( "Owner: %s", "entryvault" ), name || `#${ card.owner_user_id }` );
}

function ownerName( card ) {
    const ownerId = card.owner_user_id ? Number( card.owner_user_id ) : 0;
    if ( ! ownerId ) return "";
    const owners = ( window.EntryVaultBoot && window.EntryVaultBoot.owners ) || [];
    const owner = owners.find( ( o ) => Number( o.id ) === ownerId );
    return owner ? owner.display_name : "";
}

function ownerInitials( card ) {
    const ownerId = card.owner_user_id ? Number( card.owner_user_id ) : 0;
    if ( ! ownerId ) return null;
    const name = ownerName( card );
    if ( ! name ) return "#" + ownerId;
    const parts = String( name ).trim().split( /\s+/ );
    const initials = parts.length === 1
        ? parts[0].slice( 0, 2 )
        : ( parts[0][0] + parts[ parts.length - 1 ][0] );
    return initials.toUpperCase();
}

function avatarColor( seed ) {
    const palette = [ "#7c3aed", "#2563eb", "#0f766e", "#b45309", "#be185d", "#0891b2" ];
    let h = 0;
    for ( const ch of String( seed || "" ) ) h = ( h * 31 + ch.charCodeAt( 0 ) ) >>> 0;
    return palette[ h % palette.length ];
}

const SOURCE_COLORS = {
    wpforms: "#2271b1",
    sureforms: "#16a34a",
    gravity: "#f97316",
    gravityforms: "#f97316",
    fluent: "#7c3aed",
    fluentforms: "#7c3aed",
    forminator: "#dc2626",
    trinityforms: "#0d9488",
};

function sourceColor( source ) {
    if ( ! source ) return "#9ca3af";
    const key = String( source ).toLowerCase().replace( /[^a-z]/g, "" );
    return SOURCE_COLORS[ key ] || "#9ca3af";
}

export default function Card( { card, overlay = false, onOpen } ) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable( {
        id: String( card.id ),
        disabled: overlay,
    } );

    // Only the moving parts stay inline; the look lives in admin.css.
    const style = {
        transform: transform ? `translate(${ transform.x }px, ${ transform.y }px)` : undefined,
    };
    const cls = [ "ev-card" ];
    if ( overlay ) cls.push( "is-overlay" );
    if ( isDragging && ! overlay ) cls.push( "is-dragging" );

    // Stop drag listeners from blocking clicks on the open button.
    const stop = ( e ) => e.stopPropagation();

    const initials = ownerInitials( card );
    const assigned = !! ( card.owner_user_id && Number( card.owner_user_id ) > 0 );
    const avatarBg = assigned ? avatarColor( initials || card.owner_user_id ) : undefined;
    const ownerTitle = assigned ? ownerLabel( card ) : __( "Unassigned: no owner set", "entryvault" );

    return (
        <div ref={ setNodeRef } className={ cls.join( " " ) } style={ style } { ...listeners } { ...attributes }>
            <div className="ev-card-top">
                { card.source_plugin && (
                    <span
                        className="ev-card-source"
                        title={ /* translators: %s: form plugin name. */ sprintf( __( "Source: %s", "entryvault" ), card.source_label || card.source_plugin ) }
                        style={ { background: sourceColor( card.source_plugin ) } }
                    />
                ) }
                <strong className="ev-card-title">{ card.title || `#${ card.id }` }</strong>
                { card.is_new && <span className="ev-card-new">{ _x( "New", "badge on a card nobody has seen yet", "entryvault" ) }</span> }
            </div>
            { card.email && <div className="ev-card-email">{ card.email }</div> }
            { Array.isArray( card.tags ) && card.tags.length > 0 && (
                <div className="ev-card-tags">
                    { card.tags.map( ( t ) => (
                        <span key={ t.id } className="ev-tag" style={ { "--ev-tag": t.color || "#64748b" } }>{ t.name }</span>
                    ) ) }
                </div>
            ) }
            <div className="ev-card-foot">
                { /* How long it has been waiting, counted from arrival. Using
                     updated_at reset the age to "just now" on every tag,
                     note or owner change. */ }
                <span className="ev-card-age" title={ /* translators: %s: date and time the submission arrived. */ sprintf( __( "Arrived %s", "entryvault" ), formatSiteTime( card.created_at_gmt ) ) }>
                    { relativeAge( card.created_at_gmt || card.updated_at_gmt ) }
                </span>
                <span className="ev-card-actions">
                    <span
                        className={ assigned ? "ev-avatar" : "ev-avatar is-empty" }
                        title={ ownerTitle }
                        aria-label={ ownerTitle }
                        style={ avatarBg ? { background: avatarBg } : undefined }
                    >{ assigned ? ( initials || "?" ) : "" }</span>
                    { ! overlay && onOpen && (
                        <button
                            type="button"
                            className="ev-card-open"
                            title={ __( "Open details", "entryvault" ) }
                            aria-label={ /* translators: %s: submission title. */ sprintf( __( "Open %s", "entryvault" ), card.title || __( "this submission", "entryvault" ) ) }
                            onPointerDown={ stop }
                            onMouseDown={ stop }
                            onClick={ ( e ) => { stop( e ); onOpen( card.id ); } }
                        >{ _x( "Open", "button: open a card's details", "entryvault" ) }</button>
                    ) }
                </span>
            </div>
        </div>
    );
}
