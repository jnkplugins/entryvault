import { useState } from "@wordpress/element";
import { useDroppable } from "@dnd-kit/core";
import { __, _n, sprintf } from "@wordpress/i18n";
import Card from "./Card";

export default function Column( { stage, onOpenCard, onLoadMore, onStar } ) {
    const cards = stage.cards || [];
    // The column shows the first page of its cards; the count is the whole stage.
    const total = stage.total !== undefined && stage.total !== null ? Number( stage.total ) : cards.length;
    const left = Math.max( 0, total - cards.length );
    const [ loading, setLoading ] = useState( false );
    const accent = stage.color || "#94a3b8";
    const { isOver, setNodeRef } = useDroppable( { id: `stage-${ stage.id }` } );

    const wipLimitRaw = stage.wip_limit ?? stage.max_cards ?? null;
    const wipLimit = wipLimitRaw !== null && wipLimitRaw !== "" && Number( wipLimitRaw ) > 0
        ? Number( wipLimitRaw )
        : null;
    const overLimit = wipLimit !== null && total > wipLimit;
    const countLabel = wipLimit !== null
        ? `${ total } / ${ wipLimit }`
        : String( total );

    const cls = [ "ev-col" ];
    if ( isOver ) cls.push( "is-over" );
    if ( overLimit ) cls.push( "is-over-limit" );

    return (
        <div ref={ setNodeRef } className={ cls.join( " " ) } style={ { "--ev-stage": accent } }>
            <h3 className="ev-col-head">
                <span className="ev-col-dot" aria-hidden="true" />
                <span className="ev-col-name">{ stage.name }</span>
                <span
                    className="ev-col-count"
                    title={ overLimit ? __( "More cards than this stage's limit", "entryvault" ) : undefined }
                >{ countLabel }</span>
                { Number( stage.unread ) > 0 && ( () => {
                    /* translators: %d: unread entries in this stage. */
                    const label = sprintf( _n( "%d unread", "%d unread", Number( stage.unread ), "entryvault" ), Number( stage.unread ) );
                    return <span className="ev-col-unread" title={ label } aria-label={ label } role="img">{ Number( stage.unread ) }</span>;
                } )() }
            </h3>
            <div className="ev-col-body">
                { cards.length === 0
                    ? <p className="ev-col-empty">{ __( "No submissions", "entryvault" ) }</p>
                    : cards.map( ( c ) => <Card key={ c.id } card={ c } onOpen={ onOpenCard } onStar={ onStar } /> ) }
                { left > 0 && onLoadMore && (
                    <button
                        type="button"
                        className="button ev-col-more"
                        disabled={ loading }
                        onClick={ () => {
                            setLoading( true );
                            Promise.resolve( onLoadMore( stage.id ) ).finally( () => setLoading( false ) );
                        } }
                    >{ /* translators: %d: cards not shown yet. */ sprintf( __( "Show more (%d left)", "entryvault" ), left ) }</button>
                ) }
            </div>
        </div>
    );
}
