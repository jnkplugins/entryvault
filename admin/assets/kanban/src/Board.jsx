import { useEffect, useMemo, useState } from "@wordpress/element";
import { DndContext, PointerSensor, useSensor, useSensors, DragOverlay } from "@dnd-kit/core";
import { __, _n, sprintf } from "@wordpress/i18n";
import Column from "./Column";
import Card from "./Card";
import DetailPanel from "./DetailPanel";
import { utcMs } from "./siteTime";
import {
    fetchBoard,
    fetchPipelines,
    fetchOwners,
    moveSubmission,
    markSeen,
} from "./api";

const UNASSIGNED = "unassigned";

function unwrap( res ) {
    return res && res.data !== undefined ? res.data : res;
}

// Lower case without accents, the way the server's search compares ("jose"
// finds "José"). The server found the card and this filter hid it again.
function fold( v ) {
    return String( v || "" ).toLowerCase().normalize( "NFD" ).replace( /[\u0300-\u036f]/g, "" );
}

export default function Board( { pipelineId, onPipelineChange, initialOpenId = null } ) {
    const [ stages, setStages ] = useState( [] );
    const [ pipeline, setPipeline ] = useState( null );
    const [ pipelines, setPipelines ] = useState( [] );
    const [ pipelinesLoaded, setPipelinesLoaded ] = useState( false );
    const [ owners, setOwners ] = useState( [] );
    const [ selectedOwners, setSelectedOwners ] = useState( [] ); // ids + "unassigned"
    const [ search, setSearch ] = useState( "" );
    const [ query, setQuery ] = useState( "" ); // the search as last sent to the server
    const [ lastSeen, setLastSeen ] = useState( "" );
    const [ error, setError ] = useState( null );
    const [ activeCard, setActiveCard ] = useState( null );
    const [ openId, setOpenId ] = useState( initialOpenId );

    const sensors = useSensors( useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ) );

    function loadBoard() {
        const ownerIds = selectedOwners; // includes "unassigned" sentinel
        return fetchBoard( pipelineId, { ownerIds, q: query } )
            .then( ( res ) => {
                const data = unwrap( res ) || {};
                setPipeline( data.pipeline || null );
                setStages( data.stages || [] );
                setLastSeen( data.last_seen_gmt || "" );
            } )
            .catch( ( e ) => setError( e.message || __( "Failed to load board", "entryvault" ) ) );
    }

    useEffect( () => {
        fetchPipelines()
            .then( ( res ) => setPipelines( unwrap( res ) || [] ) )
            .catch( () => {} )
            .finally( () => setPipelinesLoaded( true ) );
        fetchOwners()
            .then( ( res ) => setOwners( unwrap( res ) || [] ) )
            .catch( () => {} );
    }, [] );

    useEffect( () => {
        loadBoard();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ pipelineId, selectedOwners.join( "," ), query ] );

    // Search the whole pipeline, not just the cards on screen: send the text to
    // the server once typing pauses.
    useEffect( () => {
        const t = setTimeout( () => setQuery( search.trim() ), 300 );
        return () => clearTimeout( t );
    }, [ search ] );

    function loadMore( stageId ) {
        const stage = ( stages || [] ).find( ( s ) => String( s.id ) === String( stageId ) );
        if ( ! stage ) return Promise.resolve();
        return fetchBoard( pipelineId, {
            ownerIds: selectedOwners,
            q: query,
            stageId,
            // Possibly one row too early, never too late: repeats are dropped below.
            offset: Math.max( 0, ( stage.cards || [] ).length - ( stage.dropped || 0 ) ),
        } )
            .then( ( res ) => {
                const data = unwrap( res ) || {};
                const more = ( data.stages || [] ).find( ( s ) => String( s.id ) === String( stageId ) );
                if ( ! more ) return;
                setStages( ( prev ) => prev.map( ( s ) => {
                    if ( String( s.id ) !== String( stageId ) ) return s;
                    const have = new Set( ( s.cards || [] ).map( ( c ) => String( c.id ) ) );
                    const add = ( more.cards || [] ).filter( ( c ) => ! have.has( String( c.id ) ) );
                    return { ...s, cards: [ ...( s.cards || [] ), ...add ], total: more.total };
                } ) );
            } )
            .catch( ( e ) => setError( e.message || __( "Failed to load board", "entryvault" ) ) );
    }

    const stagesView = useMemo( () => {
        const needle = fold( search.trim() );
        const seenTs = lastSeen ? utcMs( lastSeen ) || 0 : 0;

        return ( stages || [] ).map( ( s ) => {
            let cards = s.cards || [];

            if ( needle ) {
                cards = cards.filter( ( c ) => {
                    const t = fold( c.title );
                    const e = fold( c.email );
                    return t.includes( needle ) || e.includes( needle );
                } );
            }

            // In-memory owner filter as a fallback (server already filtered, but
            // this keeps things consistent if owner_id param is ignored upstream).
            if ( selectedOwners.length > 0 ) {
                cards = cards.filter( ( c ) => {
                    const oid = c.owner_user_id ? Number( c.owner_user_id ) : 0;
                    if ( oid === 0 ) return selectedOwners.includes( UNASSIGNED );
                    return selectedOwners.some( ( v ) => Number( v ) === oid );
                } );
            }

            cards = cards.map( ( c ) => {
                let isNew = false;
                if ( seenTs && c.created_at_gmt ) {
                    const ts = utcMs( c.created_at_gmt );
                    if ( ts && ts > seenTs ) isNew = true;
                } else if ( ! seenTs && c.created_at_gmt ) {
                    // Never marked seen — treat all as new.
                    isNew = true;
                }
                return { ...c, is_new: isNew };
            } );

            const total = s.total !== undefined && s.total !== null ? Number( s.total ) : cards.length;
            return { ...s, cards, total: Math.max( total, cards.length ) };
        } );
    }, [ stages, search, selectedOwners, lastSeen ] );

    function toggleOwner( key ) {
        setSelectedOwners( ( prev ) => {
            const has = prev.some( ( v ) => String( v ) === String( key ) );
            return has
                ? prev.filter( ( v ) => String( v ) !== String( key ) )
                : [ ...prev, key ];
        } );
    }

    async function handleMarkSeen() {
        try {
            const res = await markSeen( pipelineId );
            const data = unwrap( res ) || {};
            setLastSeen( data.last_seen_gmt || "" );
        } catch ( e ) {
            setError( e.message || __( "Could not mark all cards as seen", "entryvault" ) );
            setTimeout( () => setError( null ), 3000 );
        }
    }

    if ( error && ! pipeline ) return <div className="ev-board-msg is-error">{ error }</div>;
    if ( ! pipeline ) return <div className="ev-board-loading"><span className="jnkui-spin" aria-hidden="true" /> { __( "Loading the board…", "entryvault" ) }</div>;

    function findCardLocation( cardId ) {
        for ( const stage of stagesView ) {
            const card = ( stage.cards || [] ).find( ( c ) => String( c.id ) === String( cardId ) );
            if ( card ) return { stage, card };
        }
        return null;
    }

    // Screen-reader messages in words. The defaults read out internal ids:
    // "Draggable item 21 was dropped over droppable area stage-2".
    const cardName = ( id ) => {
        for ( const s of stages || [] ) {
            const c = ( s.cards || [] ).find( ( x ) => String( x.id ) === String( id ) );
            if ( c ) return c.title || __( "this card", "entryvault" );
        }
        return __( "this card", "entryvault" );
    };
    const stageName = ( overId ) => {
        const sid = String( overId || "" ).replace( /^stage-/, "" );
        const s = ( stages || [] ).find( ( x ) => String( x.id ) === sid );
        return s ? s.name : __( "a stage", "entryvault" );
    };
    const dragA11y = {
        screenReaderInstructions: {
            draggable: __( "To pick up a card, press space or enter. Use the arrow keys to move it, then press space or enter to drop it, or escape to cancel.", "entryvault" ),
        },
        announcements: {
            /* translators: %s: card title. */
            onDragStart: ( { active } ) => sprintf( __( "Picked up %s.", "entryvault" ), cardName( active.id ) ),
            onDragOver: ( { active, over } ) => over
                /* translators: 1: card title, 2: stage name. */
                ? sprintf( __( "%1$s is over %2$s.", "entryvault" ), cardName( active.id ), stageName( over.id ) )
                /* translators: %s: card title. */
                : sprintf( __( "%s is not over a stage.", "entryvault" ), cardName( active.id ) ),
            onDragEnd: ( { active, over } ) => over
                /* translators: 1: card title, 2: stage name. */
                ? sprintf( __( "%1$s was dropped in %2$s.", "entryvault" ), cardName( active.id ), stageName( over.id ) )
                /* translators: %s: card title. */
                : sprintf( __( "%s was dropped.", "entryvault" ), cardName( active.id ) ),
            /* translators: %s: card title. */
            onDragCancel: ( { active } ) => sprintf( __( "Moving %s was cancelled.", "entryvault" ), cardName( active.id ) ),
        },
    };

    function handleDragStart( e ) {
        const found = findCardLocation( e.active.id );
        setActiveCard( found ? found.card : null );
    }

    async function handleDragEnd( e ) {
        setActiveCard( null );
        const { active, over } = e;
        if ( ! over ) return;

        const fromLoc = findCardLocation( active.id );
        if ( ! fromLoc ) return;

        const toStageId = String( over.id ).startsWith( "stage-" )
            ? over.id.slice( 6 )
            : ( findCardLocation( over.id )?.stage?.id );

        if ( ! toStageId ) return;
        if ( String( fromLoc.stage.id ) === String( toStageId ) ) return;

        const previous = stages;
        const bump = ( s, by ) => ( s.total !== undefined && s.total !== null ? Math.max( 0, Number( s.total ) + by ) : s.total );
        const next = stages.map( ( s ) => ( String( s.id ) === String( fromLoc.stage.id )
            ? { ...s, cards: ( s.cards || [] ).filter( ( c ) => String( c.id ) !== String( active.id ) ), total: bump( s, -1 ) }
            : s
        ) ).map( ( s ) => {
            if ( String( s.id ) !== String( toStageId ) ) return s;
            // dropped: cards that arrived by drag. They sit at the top here but
            // anywhere in the server's date order, so "Show more" pages from the
            // count the server sent, not the count on screen (one was skipped).
            return { ...s, cards: [ fromLoc.card, ...( s.cards || [] ) ], total: bump( s, 1 ), dropped: ( s.dropped || 0 ) + 1 };
        } );
        setStages( next );

        try {
            await moveSubmission( active.id, Number( toStageId ) );
        } catch ( err ) {
            setStages( previous );
            setError( err.message || __( "The move failed, so the card went back.", "entryvault" ) );
            setTimeout( () => setError( null ), 3000 );
        }
    }

    // With nothing captured yet the board is five columns of "No submissions" and
    // no explanation — a new install cannot tell whether the plugin is working,
    // needs configuring, or is waiting on something. The guidance existed, but on
    // the Dashboard sub-screen that nobody opens before the board.
    const boardIsEmpty =
        ! search &&
        selectedOwners.length === 0 &&
        ( stagesView || [] ).every( ( s ) => ! ( s.cards || [] ).length );

    // Pipelines other than this one that hold open cards: an empty board then
    // points there instead of showing the first-run text ("submit one of your
    // forms"), which read wrong with 30 cards on the next board.
    const otherBoards = ( pipelines || [] ).filter(
        ( p ) => String( p.id ) !== String( pipelineId ) && Number( p.open_count ) > 0
    );

    const shownCards = ( stagesView || [] ).reduce( ( n, s ) => n + ( s.cards || [] ).length, 0 );
    const totalCards = ( stagesView || [] ).reduce( ( n, s ) => n + ( s.total || 0 ), 0 );
    const totalLabel = shownCards < totalCards
        /* translators: 1: cards shown, 2: cards in the pipeline. */
        ? sprintf( __( "%1$d of %2$d cards", "entryvault" ), shownCards, totalCards )
        /* translators: %d: number of cards on the board. */
        : sprintf( _n( "%d card", "%d cards", totalCards, "entryvault" ), totalCards );

    return (
        <>
            <div className="ev-board-toolbar jnkui-card">
                <div className="ev-board-toolbar-row">
                    <label className="ev-board-field">
                        <span>{ __( "Pipeline", "entryvault" ) }</span>
                        <select
                            id="ev-board-pipeline"
                            name="ev_board_pipeline"
                            value={ pipelineId }
                            onChange={ ( e ) => onPipelineChange && onPipelineChange( e.target.value ) }
                        >
                            { pipelines.length === 0 && pipeline && (
                                <option value={ pipeline.id }>{ pipeline.name }</option>
                            ) }
                            { pipelines.map( ( p ) => (
                                <option key={ p.id } value={ p.id }>{ p.name }</option>
                            ) ) }
                        </select>
                    </label>

                    <label className="ev-board-field ev-board-search">
                        <span className="screen-reader-text">{ __( "Search", "entryvault" ) }</span>
                        <input
                            type="search"
                            id="ev-board-search"
                            name="ev_board_search"
                            placeholder={ __( "Search name or email…", "entryvault" ) }
                            value={ search }
                            onChange={ ( e ) => setSearch( e.target.value ) }
                        />
                    </label>

                    <span className="ev-board-total">{ totalLabel }</span>

                    <button
                        type="button"
                        className="button"
                        onClick={ handleMarkSeen }
                        title={ __( "Clear the New badge from every card", "entryvault" ) }
                    >{ __( "Mark all seen", "entryvault" ) }</button>
                </div>

                <div className="ev-board-owners" role="group" aria-label={ __( "Show only cards owned by", "entryvault" ) }>
                    <span className="ev-board-owners-label">{ __( "Owner", "entryvault" ) }</span>
                    <OwnerChip
                        label={ __( "Unassigned", "entryvault" ) }
                        active={ selectedOwners.includes( UNASSIGNED ) }
                        onClick={ () => toggleOwner( UNASSIGNED ) }
                    />
                    { owners.map( ( o ) => (
                        <OwnerChip
                            key={ o.id }
                            label={ o.display_name || `#${ o.id }` }
                            active={ selectedOwners.some( ( v ) => Number( v ) === Number( o.id ) ) }
                            onClick={ () => toggleOwner( o.id ) }
                        />
                    ) ) }
                    { selectedOwners.length > 0 && (
                        <button
                            type="button"
                            className="ev-chip-clear"
                            onClick={ () => setSelectedOwners( [] ) }
                        >{ __( "Clear", "entryvault" ) }</button>
                    ) }
                </div>
            </div>

            { error && <div className="ev-board-msg is-error">{ error }</div> }
            { boardIsEmpty && pipelinesLoaded && ( otherBoards.length
                ? <OtherBoards boards={ otherBoards } onPick={ onPipelineChange } />
                : <GettingStarted /> ) }
            <DndContext sensors={ sensors } onDragStart={ handleDragStart } onDragEnd={ handleDragEnd } accessibility={ dragA11y }>
                <div className="ev-board">
                    { stagesView.map( ( s ) => <Column key={ s.id } stage={ s } onOpenCard={ setOpenId } onLoadMore={ loadMore } /> ) }
                </div>
                <DragOverlay>
                    { activeCard ? <Card card={ activeCard } overlay /> : null }
                </DragOverlay>
            </DndContext>
            { openId && (
                <DetailPanel
                    submissionId={ openId }
                    stages={ stages }
                    onClose={ () => setOpenId( null ) }
                    onMutate={ loadBoard }
                />
            ) }
        </>
    );
}

function OwnerChip( { label, active, onClick } ) {
    return (
        <button
            type="button"
            className={ active ? "ev-chip is-active" : "ev-chip" }
            aria-pressed={ active }
            onClick={ onClick }
        >{ label }</button>
    );
}

/** This board is empty, but other pipelines hold cards: say so and link them. */
function OtherBoards( { boards, onPick } ) {
    return (
        <div className="jnkui-callout ev-board-start">
            <div>
                <p><strong>{ __( "No cards on this pipeline.", "entryvault" ) }</strong></p>
                <p>{ __( "Your entries are on other pipelines. Open one below, or pick it in the Pipeline menu above.", "entryvault" ) }</p>
                <p className="ev-board-start-actions">
                    { boards.map( ( b ) => {
                        const n = Number( b.open_count );
                        return (
                            <button
                                type="button"
                                className="button"
                                key={ b.id }
                                onClick={ () => onPick && onPick( String( b.id ) ) }
                            >
                                { /* translators: 1: pipeline name, 2: number of cards on it. */ sprintf( _n( "%1$s: %2$d card", "%1$s: %2$d cards", n, "entryvault" ), b.name, n ) }
                            </button>
                        );
                    } ) }
                </p>
            </div>
        </div>
    );
}

/**
 * First-run guidance, shown on the board while nothing has been captured.
 *
 * This copy already existed on the Dashboard sub-screen, which is the second item
 * in the menu, so the screen people actually land on said only "No submissions",
 * five times over, and told a new install nothing about how entries arrive.
 */
function GettingStarted() {
    return (
        <div className="jnkui-callout ev-board-start">
            <div>
                <p><strong>{ __( "Nothing here yet. That is expected.", "entryvault" ) }</strong></p>
                <p>
                    { __( "EntryVault picks up submissions from the form plugins you already use. There is nothing to add to your forms: submit one of them once and it appears on this board within moments.", "entryvault" ) }
                </p>
                <p className="ev-board-start-actions">
                    <a className="button button-primary" href="admin.php?page=entryvault-health">
                        { __( "Check which form plugins were found", "entryvault" ) }
                    </a>{ " " }
                    <a className="button" href="admin.php?page=entryvault-import">
                        { __( "Import a CSV instead", "entryvault" ) }
                    </a>
                </p>
            </div>
        </div>
    );
}
