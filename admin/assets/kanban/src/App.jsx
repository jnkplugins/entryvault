import { useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import Board from "./Board";

const STORAGE_KEY = "entryvault.lastPipelineId";

function readStoredPipeline() {
    try {
        const v = window.localStorage.getItem( STORAGE_KEY );
        const n = v ? Number( v ) : 0;
        return n > 0 ? n : 0;
    } catch ( e ) {
        return 0;
    }
}

/** ?submission=<id>, as linked from a title in the Submissions list. */
function readLinkedSubmission() {
    try {
        const n = Number( new URLSearchParams( window.location.search ).get( "submission" ) );
        return n > 0 ? n : 0;
    } catch ( e ) {
        return 0;
    }
}

export default function App() {
    const boot = window.EntryVaultBoot || {};
    const linked = readLinkedSubmission();
    // A linked submission opens on its own pipeline, not whichever was viewed last.
    const linkedPipeline = linked ? Number( boot.linkedPipeline ) || 0 : 0;
    const initial = linkedPipeline || readStoredPipeline() || Number( boot.defaultPipeline ) || 0;
    const [ pipelineId, setPipelineId ] = useState( initial );

    function changePipeline( id ) {
        const n = Number( id );
        setPipelineId( n );
        try { window.localStorage.setItem( STORAGE_KEY, String( n ) ); } catch ( e ) {}
    }

    if ( ! pipelineId ) {
        return <div className="ev-board-msg">{ __( "No pipeline is set up yet. Deactivate and activate EntryVault once to create the default one.", "entryvault" ) }</div>;
    }
    // The Submissions list links titles to …&submission=<id>, but the board
    // ignored the parameter and opened with no panel.
    return <Board pipelineId={ pipelineId } onPipelineChange={ changePipeline } initialOpenId={ linked || null } />;
}
