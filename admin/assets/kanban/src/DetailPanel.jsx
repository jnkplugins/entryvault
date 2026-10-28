import { useEffect, useState } from "@wordpress/element";
import { __, _x, sprintf } from "@wordpress/i18n";
import { utcMs, formatSiteTime, isolate } from "./siteTime";
import {
    fetchSubmission,
    addNote,
    fetchNotes,
    deleteNote,
    patchSubmission,
    fetchOwners,
    fetchTags,
    createTag,
    attachTag,
    detachTag,
    sendEmail,
    fetchEmails,
} from "./api";

const TABS = [ "Details", "Timeline", "Notes", "Email" ];

function tabLabel( key ) {
    switch ( key ) {
        case "Details": return __( "Details", "entryvault" );
        case "Timeline": return __( "Timeline", "entryvault" );
        case "Notes": return __( "Notes", "entryvault" );
        case "Email": return __( "Email", "entryvault" );
        default: return key;
    }
}

export default function DetailPanel( { submissionId, stages = [], onClose, onMutate } ) {
    const [ data, setData ] = useState( null );
    const [ tab, setTab ] = useState( "Details" );
    const [ noteBody, setNoteBody ] = useState( "" );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( null );
    const [ owners, setOwners ] = useState( [] );
    const [ allTags, setAllTags ] = useState( [] );
    const [ tags, setTags ] = useState( [] );
    const [ notes, setNotes ] = useState( [] );
    const [ currentUser, setCurrentUser ] = useState( () => readBootUser() );

    useEffect( () => {
        if ( ! submissionId ) return;
        setData( null );
        setError( null );
        setTags( [] );
        setNotes( [] );
        fetchSubmission( submissionId )
            .then( ( res ) => {
                const d = res && res.data ? res.data : res;
                setData( d );
                setTags( d?.tags || [] );
            } )
            .catch( ( e ) => setError( e.message || __( "Could not load this submission.", "entryvault" ) ) );
        loadNotes( submissionId );
    }, [ submissionId ] );

    function loadNotes( id ) {
        fetchNotes( id )
            .then( ( res ) => setNotes( ( res && res.data ) || [] ) )
            .catch( () => {} );
    }

    function loadAllTags() {
        return fetchTags()
            .then( ( res ) => {
                const list = ( res && res.data ) || [];
                setAllTags( list );
                return list;
            } )
            .catch( () => [] );
    }

    useEffect( () => {
        fetchOwners()
            .then( ( res ) => setOwners( ( res && res.data ) || [] ) )
            .catch( () => {} );
        loadAllTags();
    }, [] );

    // Add-ons that change the entry from inside the panel (inline edits,
    // review state) announce it with this event, so the panel re-reads instead
    // of showing the old values until it is closed and reopened.
    useEffect( () => {
        function onUpdated( ev ) {
            const id = ev && ev.detail ? Number( ev.detail.id ) : 0;
            if ( ! id || id !== Number( submissionId ) ) return;
            refresh().catch( () => {} );
            notifyMutate();
        }
        window.addEventListener( "entryvault:submission-updated", onUpdated );
        return () => window.removeEventListener( "entryvault:submission-updated", onUpdated );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ submissionId ] );

    function notifyMutate() {
        if ( typeof onMutate === "function" ) onMutate();
    }

    async function refresh() {
        const res = await fetchSubmission( submissionId );
        const d = res && res.data ? res.data : res;
        setData( d );
        setTags( d?.tags || tags );
    }

    async function handleAddNote( body, parentId = null ) {
        const text = ( body !== undefined ? body : noteBody ).trim();
        if ( ! text ) return false;
        setSaving( true );
        try {
            await addNote( submissionId, text, parentId );
            if ( body === undefined ) setNoteBody( "" );
            loadNotes( submissionId );
            await refresh();
            notifyMutate();
            return true;
        } catch ( e ) {
            setError( e.message || __( "Could not save the note.", "entryvault" ) );
            return false;
        } finally {
            setSaving( false );
        }
    }

    async function handleDeleteNote( noteId ) {
        try {
            await deleteNote( submissionId, noteId );
            loadNotes( submissionId );
            // The Timeline tab reads from the entry payload; re-read it so the
            // deleted text does not stay on screen until the page is reloaded.
            refresh().catch( () => {} );
            notifyMutate();
        } catch ( e ) {
            setError( e.message || __( "Could not delete the note.", "entryvault" ) );
        }
    }

    async function patchOptimistic( field, value, optimistic ) {
        const prev = data;
        if ( optimistic ) setData( optimistic );
        try {
            await patchSubmission( submissionId, { [ field ]: value } );
            await refresh();
            notifyMutate();
        } catch ( e ) {
            setData( prev );
            setError( e.message || `Failed to update ${ field }` );
            setTimeout( () => setError( null ), 3000 );
        }
    }

    async function handleStageChange( stageId ) {
        const stage = stages.find( ( s ) => Number( s.id ) === Number( stageId ) );
        const optimistic = {
            ...data,
            submission: { ...data.submission, stage_id: Number( stageId ) },
            stage: stage ? { id: stage.id, name: stage.name, color: stage.color } : data.stage,
        };
        await patchOptimistic( "stage_id", Number( stageId ), optimistic );
    }

    async function handleOwnerChange( ownerId ) {
        const v = ownerId === "" ? 0 : Number( ownerId );
        const optimistic = {
            ...data,
            submission: { ...data.submission, owner_user_id: v || null },
        };
        await patchOptimistic( "owner_id", v, optimistic );
    }

    async function handleTitleSave( newTitle ) {
        const trimmed = newTitle.trim();
        if ( trimmed === ( data?.submission?.title || "" ) ) return;
        const optimistic = {
            ...data,
            submission: { ...data.submission, title: trimmed },
        };
        await patchOptimistic( "title", trimmed, optimistic );
    }

    async function handleAttachTag( tagId ) {
        const tag = allTags.find( ( t ) => Number( t.id ) === Number( tagId ) );
        if ( ! tag ) return;
        if ( tags.find( ( t ) => Number( t.id ) === Number( tagId ) ) ) return;
        const prev = tags;
        setTags( [ ...tags, tag ] );
        try {
            await attachTag( submissionId, Number( tagId ) );
            notifyMutate();
        } catch ( e ) {
            setTags( prev );
            setError( e.message || __( "Could not add the tag.", "entryvault" ) );
        }
    }

    async function handleDetachTag( tagId ) {
        const prev = tags;
        setTags( tags.filter( ( t ) => Number( t.id ) !== Number( tagId ) ) );
        try {
            await detachTag( submissionId, Number( tagId ) );
            notifyMutate();
        } catch ( e ) {
            setTags( prev );
            setError( e.message || __( "Could not remove the tag.", "entryvault" ) );
        }
    }

    async function handleCreateTag( name ) {
        const label = ( name || "" ).trim();
        if ( ! label ) return;
        try {
            const res = await createTag( label );
            const newId = res && res.data ? Number( res.data.id ) : 0;
            if ( ! newId ) {
                setError( __( "Could not create the tag.", "entryvault" ) );
                return;
            }
            const list = await loadAllTags();
            const created = list.find( ( t ) => Number( t.id ) === newId )
                || { id: newId, name: label };
            if ( ! tags.find( ( t ) => Number( t.id ) === newId ) ) {
                const prev = tags;
                setTags( [ ...tags, created ] );
                try {
                    await attachTag( submissionId, newId );
                    notifyMutate();
                } catch ( e ) {
                    setTags( prev );
                    setError( e.message || __( "Could not add the tag.", "entryvault" ) );
                }
            }
        } catch ( e ) {
            setError( e.message || __( "Could not create the tag.", "entryvault" ) );
        }
    }

    async function handleSendEmail( payload ) {
        await sendEmail( submissionId, payload );
        await refresh();
        notifyMutate();
        // Stay on the Email tab: the sent message now appears in the thread
        // below the composer, which is where someone replying looks for it.
    }

    return (
        <div className="ev-panel-overlay" onClick={ onClose }>
            <aside className="ev-panel" role="dialog" aria-label={ __( "Submission details", "entryvault" ) } onClick={ ( e ) => e.stopPropagation() }>
                <header className="ev-panel-head">
                    <EditableTitle
                        value={ data?.submission?.title || /* translators: %d: submission id. */ sprintf( __( "Submission #%d", "entryvault" ), submissionId ) }
                        onSave={ handleTitleSave }
                        disabled={ ! data }
                    />
                    <button type="button" className="ev-panel-close" onClick={ onClose } aria-label={ __( "Close", "entryvault" ) }>×</button>
                </header>
                { data?.submission && (
                    <div className="ev-panel-meta">
                        { data.stage?.name && (
                            <span className="ev-panel-stage" style={ { "--ev-stage": data.stage.color || "#94a3b8" } }>{ data.stage.name }</span>
                        ) }
                        { data.submission.email && <span className="ev-panel-email">{ data.submission.email }</span> }
                    </div>
                ) }

                { error && <div className="ev-panel-error">{ error }</div> }

                <nav className="ev-panel-tabs" role="tablist">
                    { TABS.map( ( t ) => (
                        <button
                            key={ t }
                            type="button"
                            role="tab"
                            aria-selected={ tab === t }
                            className={ tab === t ? "ev-panel-tab is-active" : "ev-panel-tab" }
                            onClick={ () => setTab( t ) }
                        >{ tabLabel( t ) }</button>
                    ) ) }
                </nav>

                <div className="ev-panel-body">
                    { ! data ? <p className="ev-muted"><span className="jnkui-spin" aria-hidden="true" /> { __( "Loading…", "entryvault" ) }</p> : (
                        <>
                            { tab === "Details" && (
                                <Details
                                    data={ data }
                                    stages={ stages }
                                    owners={ owners }
                                    allTags={ allTags }
                                    tags={ tags }
                                    onStageChange={ handleStageChange }
                                    onOwnerChange={ handleOwnerChange }
                                    onAttachTag={ handleAttachTag }
                                    onDetachTag={ handleDetachTag }
                                    onCreateTag={ handleCreateTag }
                                />
                            ) }
                            { tab === "Timeline" && <Timeline events={ data.timeline || [] } /> }
                            { tab === "Notes" && (
                                <NotesTab
                                    notes={ notes }
                                    owners={ owners }
                                    currentUser={ currentUser }
                                    note={ noteBody }
                                    setNote={ setNoteBody }
                                    onSave={ () => handleAddNote() }
                                    onReply={ ( body, parentId ) => handleAddNote( body, parentId ) }
                                    onDelete={ handleDeleteNote }
                                    saving={ saving }
                                />
                            ) }
                            { tab === "Email" && (
                                <EmailTab
                                    submissionId={ submissionId }
                                    defaultTo={ data.submission?.email || "" }
                                    originalSubject={ submittedSubject( data.fields ) || data.submission?.title || "" }
                                    fields={ data.fields || [] }
                                    onSend={ handleSendEmail }
                                />
                            ) }
                        </>
                    ) }
                </div>
            </aside>
        </div>
    );
}

function EditableTitle( { value, onSave, disabled } ) {
    const [ editing, setEditing ] = useState( false );
    const [ draft, setDraft ] = useState( value );

    useEffect( () => { setDraft( value ); }, [ value ] );

    if ( editing ) {
        return (
            <input
                autoFocus
                id="ev-detail-title"
                name="ev_detail_title"
                value={ draft }
                onChange={ ( e ) => setDraft( e.target.value ) }
                onBlur={ () => {
                    setEditing( false );
                    if ( draft !== value ) onSave( draft );
                } }
                onKeyDown={ ( e ) => {
                    if ( e.key === "Enter" ) e.target.blur();
                    if ( e.key === "Escape" ) { setDraft( value ); setEditing( false ); }
                } }
                className="ev-panel-title-input"
            />
        );
    }
    return (
        <strong
            onClick={ () => ! disabled && setEditing( true ) }
            title={ __( "Click to edit", "entryvault" ) }
            className={ disabled ? "ev-panel-title" : "ev-panel-title is-editable" }
        >{ value }</strong>
    );
}

function Details( { data, stages, owners, allTags, tags, onStageChange, onOwnerChange, onAttachTag, onDetachTag, onCreateTag } ) {
    const s = data.submission || {};

    return (
        <>
            <Row label={ __( "Email", "entryvault" ) } value={ s.email } />
            <Row label={ __( "Source", "entryvault" ) } value={ `${ s.source_label || s.source_plugin }${ s.source_form_title ? ` / ${ s.source_form_title }` : s.source_form_id ? ` / ${ /* translators: %s: form id. */ sprintf( __( "form %s", "entryvault" ), s.source_form_id ) }` : "" }` } />
            <RowEditor label={ __( "Stage", "entryvault" ) }>
                <select
                    id="ev-detail-stage"
                    name="ev_detail_stage"
                    value={ s.stage_id || "" }
                    onChange={ ( e ) => onStageChange( e.target.value ) }
                >
                    { stages.length === 0 && <option value="">{ data.stage?.name || "—" }</option> }
                    { stages.map( ( st ) => (
                        <option key={ st.id } value={ st.id }>{ st.name }</option>
                    ) ) }
                </select>
            </RowEditor>
            <RowEditor label={ __( "Owner", "entryvault" ) }>
                <select
                    id="ev-detail-owner"
                    name="ev_detail_owner"
                    value={ s.owner_user_id || "" }
                    onChange={ ( e ) => onOwnerChange( e.target.value ) }
                >
                    <option value="">{ __( "Unassigned", "entryvault" ) }</option>
                    { owners.map( ( o ) => (
                        <option key={ o.id } value={ o.id }>{ o.display_name }</option>
                    ) ) }
                </select>
            </RowEditor>
            <RowEditor label={ __( "Tags", "entryvault" ) }>
                <TagPicker
                    allTags={ allTags }
                    tags={ tags }
                    onAttachTag={ onAttachTag }
                    onDetachTag={ onDetachTag }
                    onCreateTag={ onCreateTag }
                />
            </RowEditor>
            <Row label={ __( "Status", "entryvault" ) } value={ statusLabel( s.status ) } />
            <Row label={ __( "Received", "entryvault" ) } value={ s.created_at_gmt ? <When iso={ s.created_at_gmt } /> : "" } />
            <h4 className="ev-panel-h">{ __( "What they sent", "entryvault" ) }</h4>
            { ( data.fields || [] ).map( ( f ) => {
                const stale = Number( f.is_stale ) === 1;
                const label = stale
                    ? <span className="ev-stale-label" title={ __( "This field is no longer on the form", "entryvault" ) }>{ f.field_label } <em className="ev-stale-tag">{ __( "old field", "entryvault" ) }</em></span>
                    : f.field_label;
                return (
                    <Row
                        key={ f.field_key }
                        label={ label }
                        value={ formatValue( f.value ) }
                        muted={ stale }
                    />
                );
            } ) }
            { /* Extension slot. Rendered empty and left empty by this app, so
                 anything appended to it by an optional add-on survives our
                 re-renders. The data attribute tells the add-on which entry is
                 on screen, and changes when the panel switches entries. */ }
            <div className="ev-detail-ext" data-ev-submission-id={ s.id }></div>
        </>
    );
}

function TagPicker( { allTags, tags, onAttachTag, onDetachTag, onCreateTag } ) {
    const [ creating, setCreating ] = useState( false );
    const [ newName, setNewName ] = useState( "" );
    const tagIds = tags.map( ( t ) => Number( t.id ) );
    const available = allTags.filter( ( t ) => ! tagIds.includes( Number( t.id ) ) );

    const ADD = "__add__";
    const CREATE = "__create__";

    function handleSelect( value ) {
        if ( value === CREATE ) {
            setCreating( true );
        } else if ( value && value !== ADD ) {
            onAttachTag( value );
        }
    }

    function submitNew() {
        const name = newName.trim();
        if ( name && typeof onCreateTag === "function" ) onCreateTag( name );
        setNewName( "" );
        setCreating( false );
    }

    return (
        <div className="ev-tagpicker">
            { tags.map( ( t ) => (
                <span key={ t.id } className="ev-tag is-removable" style={ { "--ev-tag": t.color || "#64748b" } }>
                    { t.name }
                    <button
                        type="button"
                        onClick={ () => onDetachTag( t.id ) }
                        className="ev-tag-x"
                        title={ __( "Remove tag", "entryvault" ) }
                        aria-label={ /* translators: %s: tag name. */ sprintf( __( "Remove tag %s", "entryvault" ), t.name ) }
                    >×</button>
                </span>
            ) ) }
            { creating ? (
                <span className="ev-tagpicker-new">
                    <input
                        autoFocus
                        id="ev-detail-new-tag"
                        name="ev_detail_new_tag"
                        value={ newName }
                        onChange={ ( e ) => setNewName( e.target.value ) }
                        onKeyDown={ ( e ) => {
                            if ( e.key === "Enter" ) submitNew();
                            if ( e.key === "Escape" ) { setNewName( "" ); setCreating( false ); }
                        } }
                        placeholder={ __( "New tag name", "entryvault" ) }

                    />
                    <button type="button" className="button button-small" onClick={ submitNew }>{ __( "Add", "entryvault" ) }</button>
                    <button
                        type="button"
                        onClick={ () => { setNewName( "" ); setCreating( false ); } }
                        className="ev-icon-btn is-danger"
                        title={ __( "Cancel", "entryvault" ) }
                        aria-label={ __( "Cancel", "entryvault" ) }
                    >×</button>
                </span>
            ) : (
                <select
                    id="ev-detail-add-tag"
                    name="ev_detail_add_tag"
                    value=""
                    onChange={ ( e ) => { handleSelect( e.target.value ); e.target.value = ""; } }
                    className="ev-tagpicker-add"
                    title={ __( "Add a tag to this submission", "entryvault" ) }
                >
                    <option value={ ADD }>{ __( "+ Add tag…", "entryvault" ) }</option>
                    { available.map( ( t ) => (
                        <option key={ t.id } value={ t.id }>{ t.name }</option>
                    ) ) }
                    { typeof onCreateTag === "function" && (
                        <option value={ CREATE }>{ __( "+ Create new tag…", "entryvault" ) }</option>
                    ) }
                </select>
            ) }
        </div>
    );
}

function Row( { label, value, muted } ) {
    // pre-wrap: a message typed on several lines used to collapse into one.
    return (
        <div className={ muted ? "ev-row is-muted" : "ev-row" }>
            <div className="ev-row-label">{ label }</div>
            <div className="ev-row-value">{ value || "—" }</div>
        </div>
    );
}

function RowEditor( { label, children } ) {
    return (
        <div className="ev-row is-editor">
            <div className="ev-row-label">{ label }</div>
            <div className="ev-row-value">{ children }</div>
        </div>
    );
}

function Timeline( { events } ) {
    if ( ! events.length ) return <p className="ev-muted">{ __( "No activity yet.", "entryvault" ) }</p>;
    return (
        <ul className="ev-timeline">
            { events.map( ( e ) => (
                <li key={ e.id } className={ e.deleted ? "ev-timeline-item is-deleted" : "ev-timeline-item" }>
                    <div className="ev-timeline-when"><When iso={ e.created_at_gmt } /></div>
                    <div className="ev-timeline-what">
                        { e.summary || formatTimelineData( e ) }
                    </div>
                </li>
            ) ) }
        </ul>
    );
}

function NotesTab( { notes, owners, currentUser, note, setNote, onSave, onReply, onDelete, saving } ) {
    const tree = buildNoteTree( notes );
    const youName = currentUser?.display_name
        ? currentUser.display_name
        : currentUser?.id
            ? /* translators: %d: user id. */ sprintf( __( "User #%d", "entryvault" ), currentUser.id )
            : "";
    /* translators: %s: name of the person writing the note. */
    const youLabel = youName ? sprintf( __( "Posting as %s", "entryvault" ), youName ) : "";

    return (
        <>
            { youLabel && (
                <div className="ev-muted ev-small">{ youLabel }</div>
            ) }
            <textarea
                id="ev-detail-note"
                name="ev_detail_note"
                value={ note }
                onChange={ ( e ) => setNote( e.target.value ) }
                placeholder={ __( "Add a note for your team. The customer never sees it.", "entryvault" ) }
                className="ev-textarea"
            />
            <div className="ev-compose-foot">
                <span className="ev-muted ev-small">{ __( "Tip: **bold**, *italic*, lists and links work.", "entryvault" ) }</span>
                <button
                    type="button"
                    className="button button-primary"
                    onClick={ onSave }
                    disabled={ saving || ! note.trim() }
                >{ saving ? __( "Saving…", "entryvault" ) : __( "Add note", "entryvault" ) }</button>
            </div>

            <div className="ev-notes">
                { tree.length === 0 && <p className="ev-muted">{ __( "No notes yet.", "entryvault" ) }</p> }
                { tree.map( ( n ) => (
                    <NoteItem
                        key={ n.id }
                        note={ n }
                        depth={ 0 }
                        owners={ owners }
                        currentUser={ currentUser }
                        onReply={ onReply }
                        onDelete={ onDelete }
                        saving={ saving }
                    />
                ) ) }
            </div>
        </>
    );
}

function NoteItem( { note, depth, owners, currentUser, onReply, onDelete, saving } ) {
    const [ replying, setReplying ] = useState( false );
    const [ replyBody, setReplyBody ] = useState( "" );

    const meta = note.meta || {};
    const authorId = Number( meta.user_id || note.user_id || 0 );
    const author = ( owners || [] ).find( ( o ) => Number( o.id ) === authorId );
    const authorName = author?.display_name || ( authorId ? /* translators: %d: user id. */ sprintf( __( "User #%d", "entryvault" ), authorId ) : __( "Unknown", "entryvault" ) );
    const isAuthor = currentUser?.id && Number( currentUser.id ) === authorId;
    const canDelete = isAuthor || !! currentUser?.is_admin;
    const deleted = !! meta.deleted_at;
    const body = note.data?.body || "";

    async function submitReply() {
        const ok = await onReply( replyBody, note.id );
        if ( ok ) {
            setReplyBody( "" );
            setReplying( false );
        }
    }

    return (
        <div className={ depth ? "ev-note is-reply" : "ev-note" }>
            <div className="ev-note-head">
                <strong>{ authorName }</strong>
                <span title={ isolate( formatWhen( note.created_at_gmt ) ) }><bdi>{ formatRelative( note.created_at_gmt ) }</bdi></span>
                { canDelete && ! deleted && (
                    <button
                        type="button"
                        onClick={ () => onDelete( note.id ) }
                        title={ __( "Delete note", "entryvault" ) }
                        aria-label={ __( "Delete note", "entryvault" ) }
                        className="ev-icon-btn is-danger ev-note-del"
                    >×</button>
                ) }
            </div>
            <div className={ deleted ? "ev-note-body is-deleted" : "ev-note-body" }>
                { deleted
                    ? __( "[deleted]", "entryvault" )
                    : <span dangerouslySetInnerHTML={ { __html: renderMarkdown( body ) } } />
                }
            </div>
            { ! deleted && (
                <div className="ev-note-actions">
                    { ! replying ? (
                        <button
                            type="button"
                            onClick={ () => setReplying( true ) }
                            className="ev-link-btn"
                        >{ __( "Reply", "entryvault" ) }</button>
                    ) : (
                        <div className="ev-note-reply">
                            <textarea
                                id={ `ev-detail-reply-${ note.id }` }
                                name={ `ev_detail_reply_${ note.id }` }
                                value={ replyBody }
                                onChange={ ( e ) => setReplyBody( e.target.value ) }
                                placeholder={ __( "Reply…", "entryvault" ) }
                                className="ev-textarea is-small"
                            />
                            <div className="ev-btn-row">
                                <button
                                    type="button"
                                    className="button button-primary button-small"
                                    disabled={ saving || ! replyBody.trim() }
                                    onClick={ submitReply }
                                >{ saving ? __( "Saving…", "entryvault" ) : __( "Post reply", "entryvault" ) }</button>
                                <button
                                    type="button"
                                    className="button button-small"
                                    onClick={ () => { setReplying( false ); setReplyBody( "" ); } }
                                >{ __( "Cancel", "entryvault" ) }</button>
                            </div>
                        </div>
                    ) }
                </div>
            ) }
            { ( note.children || [] ).map( ( child ) => (
                <NoteItem
                    key={ child.id }
                    note={ child }
                    depth={ depth + 1 }
                    owners={ owners }
                    currentUser={ currentUser }
                    onReply={ onReply }
                    onDelete={ onDelete }
                    saving={ saving }
                />
            ) ) }
        </div>
    );
}

function buildNoteTree( notes ) {
    const byId = {};
    const roots = [];
    ( notes || [] ).forEach( ( n ) => { byId[ n.id ] = { ...n, children: [] }; } );
    Object.values( byId ).forEach( ( n ) => {
        const pid = n.parent_id;
        if ( pid && byId[ pid ] ) {
            byId[ pid ].children.push( n );
        } else {
            roots.push( n );
        }
    } );
    return roots;
}

// Timeline dates. The raw event id ("· email_sent") used to follow the date.
// Stored UTC values, shown on the site's clock (not the browser's).
function formatWhen( iso ) {
    if ( ! iso ) return "";
    return formatSiteTime( iso );
}

// A date and clock time, isolated with <bdi> so Arabic or Hebrew around it
// cannot reorder its parts ("Oct 2026, 8:33 28").
function When( { iso } ) {
    const text = formatWhen( iso );
    return text ? <bdi className="ev-when">{ text }</bdi> : null;
}

function formatRelative( iso ) {
    if ( ! iso ) return "";
    const t = utcMs( iso );
    if ( isNaN( t ) ) return iso;
    const delta = ( Date.now() - t ) / 1000;
    if ( delta < 60 ) return __( "just now", "entryvault" );
    /* translators: %d: number of minutes. */
    if ( delta < 3600 ) return sprintf( __( "%dm ago", "entryvault" ), Math.floor( delta / 60 ) );
    /* translators: %d: number of hours. */
    if ( delta < 86400 ) return sprintf( __( "%dh ago", "entryvault" ), Math.floor( delta / 3600 ) );
    /* translators: %d: number of days. */
    if ( delta < 604800 ) return sprintf( __( "%dd ago", "entryvault" ), Math.floor( delta / 86400 ) );
    return formatWhen( iso );
}

function readBootUser() {
    if ( typeof window === "undefined" ) return null;
    const boot = window.EntryVaultBoot || window.entryvaultBoot || null;
    if ( boot && boot.currentUser ) return boot.currentUser;
    if ( boot && boot.user ) return boot.user;
    return null;
}

// Tiny markdown renderer mirroring the PHP subset. Escapes HTML first, then
// converts: **bold**, *italic*, `code`, [text](http(s)://url), > quote,
// "- "/"* " lists, "1. " ordered lists, blank-line paragraphs.
function renderMarkdown( body ) {
    if ( ! body ) return "";
    const escape = ( s ) => String( s )
        .replace( /&/g, "&amp;" )
        .replace( /</g, "&lt;" )
        .replace( />/g, "&gt;" )
        .replace( /"/g, "&quot;" )
        .replace( /'/g, "&#39;" );
    const escUrl = ( u ) => /^https?:\/\//i.test( u ) ? escape( u ) : "";
    const inline = ( t ) => {
        t = t.replace( /`([^`\n]+)`/g, ( _m, c ) => `<code>${ c }</code>` );
        t = t.replace( /\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/gi, ( _m, label, url ) => {
            const safe = escUrl( url );
            if ( ! safe ) return label;
            return `<a href="${ safe }" rel="noopener noreferrer" target="_blank">${ label }</a>`;
        } );
        t = t.replace( /\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>" );
        t = t.replace( /(^|[^*\w])\*([^*\n]+)\*(?!\*)/g, "$1<em>$2</em>" );
        return t;
    };
    const escaped = escape( body.replace( /\r\n?/g, "\n" ) );
    const blocks = escaped.split( /\n[ \t]*\n+/ );
    const out = [];
    for ( const block of blocks ) {
        const lines = block.split( "\n" ).filter( ( l ) => l.length > 0 );
        if ( lines.length === 0 ) continue;
        if ( lines.every( ( l ) => /^&gt;\s?/.test( l ) ) ) {
            out.push( "<blockquote>" + inline( lines.map( ( l ) => l.replace( /^&gt;\s?/, "" ) ).join( "<br>" ) ) + "</blockquote>" );
        } else if ( lines.every( ( l ) => /^[\-*]\s+/.test( l ) ) ) {
            out.push( "<ul>" + lines.map( ( l ) => "<li>" + inline( l.replace( /^[\-*]\s+/, "" ) ) + "</li>" ).join( "" ) + "</ul>" );
        } else if ( lines.every( ( l ) => /^\d+\.\s+/.test( l ) ) ) {
            out.push( "<ol>" + lines.map( ( l ) => "<li>" + inline( l.replace( /^\d+\.\s+/, "" ) ) + "</li>" ).join( "" ) + "</ol>" );
        } else {
            out.push( "<p>" + inline( lines.join( "<br>" ) ) + "</p>" );
        }
    }
    return out.join( "\n" );
}

/**
 * The subject the person typed into the form, if the form had one. The reply
 * used to default to "Re: <their own name>" (the entry title), so customers got
 * an email titled with their name instead of what they had asked about.
 */
function submittedSubject( fields ) {
    const list = ( fields || [] ).filter( ( f ) => f && ! Number( f.is_stale ) && f.value );
    const hit = list.find( ( f ) => /subject|topic|regarding/i.test( `${ f.field_key } ${ f.field_label }` ) );
    return hit ? String( hit.value ).trim() : "";
}

/**
 * Subject line for a reply: "Re: " plus whatever they wrote, unless it already
 * carries one. Starting blank meant typing it by hand every time, and a reply
 * without the original subject is harder for the recipient to place.
 */
function replySubject( original ) {
    const subject = String( original || "" ).trim();
    if ( ! subject ) return "";
    return /^re:/i.test( subject ) ? subject : `Re: ${ subject }`;
}

/** The original message, quoted, so it can be read while replying. */
function quoted( fields ) {
    const lines = ( fields || [] )
        .filter( ( f ) => f && f.value && ! f.is_stale )
        .map( ( f ) => `> ${ f.field_label }: ${ String( f.value ).replace( /\n/g, "\n> " ) }` );
    return lines.length ? `\n\n---\n${ lines.join( "\n" ) }` : "";
}

function EmailTab( { submissionId, defaultTo, onSend, originalSubject, fields } ) {
    const [ to, setTo ] = useState( defaultTo );
    // The composer sat on a different tab from the message being answered, so
    // replying meant switching back and forth to remember what was asked.
    const [ subject, setSubject ] = useState( replySubject( originalSubject ) );
    const [ body, setBody ] = useState( quoted( fields ) );
    const [ sending, setSending ] = useState( false );
    const [ status, setStatus ] = useState( null );
    const [ thread, setThread ] = useState( [] );

    // The readme promises the thread is kept alongside the submission, but this
    // tab only ever showed an empty composer. Load the stored messages.
    function loadThread() {
        return fetchEmails( submissionId )
            .then( ( res ) => setThread( ( res && res.data ) || [] ) )
            .catch( () => {} );
    }
    useEffect( () => {
        loadThread();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ submissionId ] );

    async function handleSend() {
        if ( ! to.trim() || ! subject.trim() || ! body.trim() ) return;
        setSending( true );
        setStatus( null );
        try {
            await onSend( { to, subject, body, html: false } );
            setStatus( __( "Sent.", "entryvault" ) );
            setSubject( replySubject( originalSubject ) );
            setBody( quoted( fields ) );
            await loadThread();
        } catch ( e ) {
            const reason = e && e.data && e.data.reason ? e.data.reason : "";
            setStatus( reason
                /* translators: %s: the mail server's reason. */
                ? sprintf( __( "Could not send the email: %s", "entryvault" ), reason )
                : ( e.message || __( "Could not send the email.", "entryvault" ) ) );
            await loadThread();
        } finally {
            setSending( false );
        }
    }

    return (
        <div className="ev-email">
            <label className="ev-label" htmlFor="ev-email-to">{ _x( "To", "email recipient", "entryvault" ) }
                <input
                    type="email"
                    id="ev-email-to"
                    name="ev_email_to"
                    value={ to }
                    onChange={ ( e ) => setTo( e.target.value ) }
                />
            </label>
            <label className="ev-label" htmlFor="ev-email-subject">{ __( "Subject", "entryvault" ) }
                <input
                    type="text"
                    id="ev-email-subject"
                    name="ev_email_subject"
                    value={ subject }
                    onChange={ ( e ) => setSubject( e.target.value ) }
                />
            </label>
            <label className="ev-label" htmlFor="ev-email-body">{ __( "Message", "entryvault" ) }
                <textarea
                    id="ev-email-body"
                    name="ev_email_body"
                    value={ body }
                    onChange={ ( e ) => setBody( e.target.value ) }
                    className="ev-textarea is-tall"
                />
            </label>
            <div className="ev-btn-row">
                <button
                    type="button"
                    className="button button-primary"
                    onClick={ handleSend }
                    disabled={ sending || ! to.trim() || ! subject.trim() || ! body.trim() }
                >{ sending ? __( "Sending…", "entryvault" ) : __( "Send", "entryvault" ) }</button>
                { status && <span className="ev-muted ev-small" role="status">{ status }</span> }
            </div>
            <h4 className="ev-panel-h">{ __( "Conversation", "entryvault" ) }</h4>
            { thread.length === 0 && <p className="ev-muted">{ __( "No emails yet.", "entryvault" ) }</p> }
            { thread.slice().reverse().map( ( m ) => (
                <div key={ m.id } className={ [ "ev-mail", m.direction === "in" ? "is-in" : "is-out", m.error ? "is-failed" : "" ].join( " " ).trim() }>
                    <div className="ev-mail-meta">
                        { m.direction === "in"
                            ? /* translators: %s: sender address. */ sprintf( __( "From %s", "entryvault" ), m.from )
                            : /* translators: %s: recipient address. */ sprintf( __( "To %s", "entryvault" ), m.to ) } · <When iso={ m.created_at_gmt } />
                    </div>
                    { m.error && (
                        <div className="ev-mail-failed" role="note">{ /* translators: %s: the mail server's reason. */ sprintf( __( "Not sent: %s", "entryvault" ), m.error ) }</div>
                    ) }
                    <div className="ev-mail-subject">{ m.subject || __( "(no subject)", "entryvault" ) }</div>
                    <div className="ev-mail-body">{ m.body }</div>
                </div>
            ) ) }
        </div>
    );
}

function statusLabel( status ) {
    switch ( status ) {
        case "open": return _x( "Open", "submission status", "entryvault" );
        case "archived": return _x( "Archived", "submission status", "entryvault" );
        case "won": return _x( "Won", "submission status", "entryvault" );
        case "lost": return _x( "Lost", "submission status", "entryvault" );
        default: return status;
    }
}

function formatValue( v ) {
    if ( v == null ) return "—";
    if ( typeof v === "object" ) return JSON.stringify( v );
    return String( v );
}

// Every type Timeline::log() actually writes needs a case here. Anything missing falls
// through to JSON.stringify and the user reads a raw payload in their own activity
// feed — which is what tag/owner changes and the `stage_changed` variant used to do.
function formatTimelineData( e ) {
    if ( ! e.data ) return e.type;
    const d = e.data;
    switch ( e.type ) {
        case "stage_change":
            /* translators: 1: previous stage name, 2: new stage name. */
            return sprintf( __( "Moved from %1$s to %2$s", "entryvault" ), d.from, d.to );
        case "stage_changed":
            // Older/alternate call site records stage IDs rather than names.
            return __( "Moved to a new stage", "entryvault" );
        case "note":
            return d.body || __( "(empty note)", "entryvault" );
        case "status":
            /* translators: 1: event name, 2: source name. */
            return d.source ? sprintf( __( "%1$s from %2$s", "entryvault" ), d.event || __( "created", "entryvault" ), d.source ) : ( d.event || "status" );
        case "email_sent":
            /* translators: 1: recipient, 2: subject. */
            return d.subject ? sprintf( __( "Email to %1$s: %2$s", "entryvault" ), d.to, d.subject )
                /* translators: %s: recipient. */
                : sprintf( __( "Email to %s", "entryvault" ), d.to );
        case "tag_added":
            /* translators: %s: tag name. */
            return sprintf( __( "Tagged %s", "entryvault" ), d.tag_name || `#${ d.tag_id }` );
        case "tag_removed":
            /* translators: %s: tag name. */
            return sprintf( __( "Removed tag %s", "entryvault" ), d.tag_name || `#${ d.tag_id }` );
        case "owner_assigned":
            /* translators: %s: user display name. */
            return sprintf( __( "Assigned to %s", "entryvault" ), d.to || /* translators: %d: user id. */ sprintf( __( "User #%d", "entryvault" ), d.user_id ) );
        case "owner_unassigned":
            /* translators: %s: user display name. */
            return d.from ? sprintf( __( "Unassigned from %s", "entryvault" ), d.from ) : __( "Unassigned", "entryvault" );
        default:
            return JSON.stringify( d );
    }
}
