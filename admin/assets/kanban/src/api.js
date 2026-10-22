import apiFetch from "@wordpress/api-fetch";

export async function fetchBoard( pipelineId, params = {} ) {
    const qs = [];
    const ownerIds = Array.isArray( params.ownerIds ) ? params.ownerIds : [];
    for ( const id of ownerIds ) {
        qs.push( `owner_id[]=${ encodeURIComponent( id ) }` );
    }
    const path = qs.length
        ? `/entryvault/v1/board/${ pipelineId }?${ qs.join( "&" ) }`
        : `/entryvault/v1/board/${ pipelineId }`;
    return apiFetch( { path } );
}

export async function fetchPipelines() {
    return apiFetch( { path: `/entryvault/v1/pipelines` } );
}

export async function markSeen( pipelineId ) {
    return apiFetch( {
        path: `/entryvault/v1/pipelines/${ pipelineId }/seen`,
        method: "POST",
    } );
}

export async function moveSubmission( submissionId, stageId ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/move`,
        method: "POST",
        data: { stage_id: stageId },
    } );
}

export async function fetchSubmission( submissionId ) {
    return apiFetch( { path: `/entryvault/v1/submissions/${ submissionId }` } );
}

export async function addNote( submissionId, body, parentId = null ) {
    const data = { body };
    if ( parentId ) data.parent_id = Number( parentId );
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/notes`,
        method: "POST",
        data,
    } );
}

export async function fetchNotes( submissionId ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/notes`,
    } );
}

export async function deleteNote( submissionId, noteId ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/notes/${ noteId }`,
        method: "DELETE",
    } );
}

export async function patchSubmission( submissionId, body ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }`,
        method: "PATCH",
        data: body,
    } );
}

export async function fetchOwners() {
    return apiFetch( { path: `/entryvault/v1/owners` } );
}

export async function fetchTags() {
    return apiFetch( { path: `/entryvault/v1/tags` } );
}

export async function createTag( name, color = null ) {
    const data = { name };
    if ( color ) data.color = color;
    return apiFetch( {
        path: `/entryvault/v1/tags`,
        method: "POST",
        data,
    } );
}

export async function attachTag( submissionId, tagId ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/tags`,
        method: "POST",
        data: { tag_id: tagId },
    } );
}

export async function detachTag( submissionId, tagId ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/tags/${ tagId }`,
        method: "DELETE",
    } );
}

export async function fetchEmails( submissionId ) {
    return apiFetch( { path: `/entryvault/v1/submissions/${ submissionId }/emails` } );
}

export async function sendEmail( submissionId, payload ) {
    return apiFetch( {
        path: `/entryvault/v1/submissions/${ submissionId }/email`,
        method: "POST",
        data: payload,
    } );
}
