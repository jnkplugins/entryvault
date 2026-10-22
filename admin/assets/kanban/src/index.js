import { createRoot } from "@wordpress/element";
import App from "./App";

document.addEventListener( "DOMContentLoaded", () => {
    const el = document.getElementById( "entryvault-kanban-root" );
    if ( el ) createRoot( el ).render( <App /> );
} );
