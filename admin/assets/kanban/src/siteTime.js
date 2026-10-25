/**
 * Milliseconds since the epoch for a stored "Y-m-d H:i:s".
 *
 * EntryVault stores times with current_time( 'mysql' ): the site's own clock,
 * no zone. Reading them as UTC made every card on an India-time site 5.5 h
 * younger ("just now" for an entry from the morning). The server sends the
 * site's current UTC offset in EntryVaultBoot.gmtOffset (seconds).
 */
export function siteTimeMs( stored ) {
    if ( ! stored ) return NaN;
    const asUtc = Date.parse( String( stored ).replace( " ", "T" ) + "Z" );
    const offset = Number( ( window.EntryVaultBoot && window.EntryVaultBoot.gmtOffset ) || 0 );
    return asUtc - offset * 1000;
}
