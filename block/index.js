/* global wp */
( function ( wp ) {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, SelectControl, RangeControl,
        ToggleControl, Spinner, Button } = wp.components;
    const { createElement: h, Fragment, useEffect, useState } = wp.element;
    const { __ } = wp.i18n;
    const apiFetch = wp.apiFetch;

    const headingLevels = [
        { label: __( 'Heading 2', 'approved-resource-list' ), value: '2' },
        { label: __( 'Heading 3', 'approved-resource-list' ), value: '3' },
        { label: __( 'Heading 4', 'approved-resource-list' ), value: '4' }
    ];

    // Keep preview requests tied to the data inputs, not presentation controls.
    function useResources( limit, revision ) {
        const [ result, setResult ] = useState( { state: 'loading', items: [] } );
        useEffect( () => {
            let active = true;
            const controller = new AbortController();
            setResult( { state: 'loading', items: [] } );
            apiFetch( {
                path: '/approved-resource-list/v1/preview?limit=' + encodeURIComponent( limit ),
                signal: controller.signal
            } ).then( ( data ) => {
                if ( ! active ) return;
                if ( ! data || ! Array.isArray( data.items ) ||
                    ! [ 'ready', 'empty' ].includes( data.state ) ) {
                    throw new Error( 'Invalid preview response' );
                }
                setResult( data );
            } ).catch( () => {
                if ( active ) {
                    setResult( { state: 'unavailable', items: [] } );
                }
            } );
            // Abort obsolete requests when editors change the limit or remove the block.
            return () => {
                active = false;
                controller.abort();
            };
        }, [ limit, revision ] );
        return result;
    }

    function ResourcePreview( { result, showSummary, onRetry } ) {
        if ( result.state === 'loading' ) {
            return h( 'div', { role: 'status' }, h( Spinner ),
                __( 'Loading resources…', 'approved-resource-list' ) );
        }
        if ( result.state === 'unavailable' ) {
            return h( Fragment, null,
                h( 'p', { role: 'status' }, __( 'Resources are temporarily unavailable.', 'approved-resource-list' ) ),
                h( Button, { variant: 'secondary', onClick: onRetry },
                    __( 'Retry preview', 'approved-resource-list' ) ) );
        }
        if ( result.state === 'empty' ) {
            return h( 'p', { role: 'status' }, __( 'No resources are available at the moment.', 'approved-resource-list' ) );
        }
        // Render text, not HTML from the API. Links are noninteractive in the editor.
        return h( 'ul', { className: 'arl-list' }, result.items.map( ( item ) =>
            h( 'li', { className: 'arl-item', key: item.id },
                h( 'span', { className: 'arl-preview-link' }, item.title ),
                showSummary && item.summary ? h( 'p', null, item.summary ) : null
            )
        ) );
    }

    function Edit( { attributes, setAttributes } ) {
        const { heading, headingLevel, limit, showSummary } = attributes;
        const [ revision, setRevision ] = useState( 0 );
        const count = Number.isInteger( limit ) ? Math.max( 1, Math.min( 12, limit ) ) : 6;
        const level = [ 2, 3, 4 ].includes( headingLevel ) ? headingLevel : 2;
        const result = useResources( count, revision );
        const blockProps = useBlockProps();
        return h( Fragment, null,
            h( InspectorControls, null,
                h( PanelBody, { title: __( 'Resource list settings', 'approved-resource-list' ), initialOpen: true },
                    h( TextControl, {
                        label: __( 'Heading', 'approved-resource-list' ), value: heading || '',
                        onChange: ( value ) => setAttributes( { heading: value } )
                    } ),
                    h( SelectControl, {
                        label: __( 'Heading level', 'approved-resource-list' ),
                        help: __( 'Choose a level consistent with the page outline.', 'approved-resource-list' ),
                        value: String( level ), options: headingLevels,
                        onChange: ( value ) => setAttributes( { headingLevel: Number( value ) } )
                    } ),
                    h( RangeControl, {
                        label: __( 'Number of resources', 'approved-resource-list' ),
                        value: count, min: 1, max: 12,
                        onChange: ( value ) => {
                            if ( Number.isInteger( value ) ) setAttributes( { limit: value } );
                        }
                    } ),
                    h( ToggleControl, {
                        label: __( 'Show resource summaries', 'approved-resource-list' ), checked: showSummary !== false,
                        onChange: ( value ) => setAttributes( { showSummary: value } )
                    } ),
                    h( 'p', null, __( 'Content market and language are managed by site configuration.', 'approved-resource-list' ) )
                )
            ),
            h( 'div', blockProps,
                h( 'h' + level, { className: 'arl-heading' }, heading || __( 'Resources', 'approved-resource-list' ) ),
                h( ResourcePreview, { result, showSummary: showSummary !== false,
                    onRetry: () => setRevision( ( value ) => value + 1 ) } )
            )
        );
    }

    registerBlockType( 'platform/resource-list', {
        edit: Edit,
        // Save settings only. Resource data must never be serialized into post content.
        save: () => null
    } );
} )( window.wp );
