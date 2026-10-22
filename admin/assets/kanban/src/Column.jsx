import { useDroppable } from "@dnd-kit/core";
import { __ } from "@wordpress/i18n";
import Card from "./Card";

export default function Column( { stage, onOpenCard } ) {
    const cards = stage.cards || [];
    const accent = stage.color || "#94a3b8";
    const { isOver, setNodeRef } = useDroppable( { id: `stage-${ stage.id }` } );

    const wipLimitRaw = stage.wip_limit ?? stage.max_cards ?? null;
    const wipLimit = wipLimitRaw !== null && wipLimitRaw !== "" && Number( wipLimitRaw ) > 0
        ? Number( wipLimitRaw )
        : null;
    const overLimit = wipLimit !== null && cards.length > wipLimit;
    const countLabel = wipLimit !== null
        ? `${ cards.length } / ${ wipLimit }`
        : String( cards.length );

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
            </h3>
            <div className="ev-col-body">
                { cards.length === 0
                    ? <p className="ev-col-empty">{ __( "No submissions", "entryvault" ) }</p>
                    : cards.map( ( c ) => <Card key={ c.id } card={ c } onOpen={ onOpenCard } /> ) }
            </div>
        </div>
    );
}
