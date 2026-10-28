/**
 * Times on the board.
 *
 * EntryVault stores every time in UTC (since 1.17.0). The REST API sends each
 * one twice: `created_at` on the site's clock (as before, for integrations) and
 * `created_at_gmt` in UTC. The board reads the *_gmt value as UTC and shows
 * clock times in the site's zone from Settings > General, whatever zone the
 * viewer's browser is in.
 */

/** Milliseconds since the epoch for a stored UTC "Y-m-d H:i:s" (NaN if empty). */
export function utcMs( stored ) {
    if ( ! stored ) return NaN;
    const s = String( stored ).trim().replace( " ", "T" );
    return Date.parse( /[zZ]|[+-]\d\d:?\d\d$/.test( s ) ? s : s + "Z" );
}

/** The site's zone: an IANA name, or "+05:30" style for a fixed-offset site. */
function siteZone() {
    return ( window.EntryVaultBoot && window.EntryVaultBoot.timezone ) || "UTC";
}

/** Offset in ms for "+05:30" / "-03:00" / "UTC+5:30" style zones, else null. */
export function fixedOffsetMs( zone ) {
    const m = /^(?:UTC|GMT)?([+-])(\d{1,2}):?(\d{2})?$/.exec( String( zone ) );
    if ( ! m ) return null;
    const ms = ( Number( m[ 2 ] ) * 60 + Number( m[ 3 ] || 0 ) ) * 60000;
    return m[ 1 ] === "-" ? -ms : ms;
}

/**
 * The user's WordPress language as a BCP 47 tag ("ko-KR", "ar"), sent in the
 * boot data, so dates follow Profile > Language and not the browser. Falls back
 * to undefined (the browser's locale) when missing or not known to Intl.
 */
let cachedLocale = null;
export function siteLocale() {
    if ( cachedLocale !== null ) return cachedLocale || undefined;
    const raw = ( typeof window !== "undefined" && window.EntryVaultBoot && window.EntryVaultBoot.locale ) || "";
    const tag = String( raw ).replace( /_/g, "-" );
    cachedLocale = "";
    if ( tag ) {
        try {
            if ( Intl.DateTimeFormat.supportedLocalesOf( [ tag ] ).length ) {
                cachedLocale = tag;
            } else {
                // "de-DE-formal" and the like: try the language-region part.
                const base = tag.split( "-" ).slice( 0, 2 ).join( "-" );
                if ( Intl.DateTimeFormat.supportedLocalesOf( [ base ] ).length ) cachedLocale = base;
            }
        } catch ( e ) {
            cachedLocale = "";
        }
    }
    return cachedLocale || undefined;
}

/**
 * Wrap text for a title attribute in Unicode isolates (FSI ... PDI), the plain
 * text twin of <bdi>, so a date keeps its order inside RTL text.
 */
export function isolate( text ) {
    return text ? "\u2068" + text + "\u2069" : "";
}

const DEFAULT_OPTS = { year: "numeric", month: "short", day: "numeric", hour: "numeric", minute: "2-digit" };

/**
 * A stored UTC time as a date and clock time in the site's zone, e.g.
 * "Oct 27, 2026, 9:05 AM". Returns the input unchanged when it cannot be read.
 */
export function formatSiteTime( stored, opts = DEFAULT_OPTS ) {
    const t = utcMs( stored );
    if ( isNaN( t ) ) return stored ? String( stored ) : "";
    const zone = siteZone();
    const fixed = fixedOffsetMs( zone );
    const locale = siteLocale();
    try {
        if ( fixed !== null ) {
            // Shift by hand and format as UTC: works where Intl does not know
            // offset zones.
            return new Intl.DateTimeFormat( locale, { ...opts, timeZone: "UTC" } ).format( new Date( t + fixed ) );
        }
        return new Intl.DateTimeFormat( locale, { ...opts, timeZone: zone } ).format( new Date( t ) );
    } catch ( e ) {
        return new Intl.DateTimeFormat( locale, { ...opts, timeZone: "UTC" } ).format( new Date( t ) ) + " UTC";
    }
}
