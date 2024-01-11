import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
	withSelect,
	withDispatch,
	dispatch,
	subscribe,
	select,
} from '@wordpress/data';
import { compose } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import AsyncSelect from 'react-select/async';
import { components } from 'react-select';
import reactSelectStyles from 'gutenberg-react-select-styles';
import { DndContext } from '@dnd-kit/core';
import { restrictToParentElement } from '@dnd-kit/modifiers';
import {
	arrayMove,
	SortableContext,
	horizontalListSortingStrategy,
	useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
const { isSavingPost } = select( 'core/editor' );
const MultiValue = ( props ) => {
	const onMouseDown = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
	};
	const innerProps = { ...props.innerProps, onMouseDown };
	const { attributes, listeners, setNodeRef, transform, transition } =
		useSortable( {
			id: props.data.value,
		} );
	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
	};
	return (
		<div
			style={ style }
			ref={ setNodeRef }
			{ ...attributes }
			{ ...listeners }
		>
			<components.MultiValue { ...props } innerProps={ innerProps } />
		</div>
	);
};

const MultiValueRemove = ( props ) => {
	return (
		<components.MultiValueRemove
			{ ...props }
			innerProps={ {
				onPointerDown: ( e ) => e.stopPropagation(),
				...props.innerProps,
			} }
		/>
	);
};

// First add our rest api endpoint to core's registry.
dispatch( 'core' ).addEntities( [
	{
		name: 'bylines',
		kind: 'bylines/v1',
		baseURL: '/bylines/v1/bylines',
		key: 'value',
	},
] );

// We need to subscribe to the post saved event, because this plugin
// might transform a user into a byline on save, hence we need to invalidate
// the cached byline query after save so that the correct bylines are selected.
let shouldFetchBylinesAfterSave = false;
subscribe( () => {
	if ( isSavingPost() ) {
		shouldFetchBylinesAfterSave = true;
	} else if ( shouldFetchBylinesAfterSave ) {
		shouldFetchBylinesAfterSave = false;
		dispatch( 'core/data' ).invalidateResolution(
			'core',
			'getEntityRecords',
			[ 'bylines/v1', 'bylines', { per_page: -1 } ]
		);
	}
} );

// Let's roll out the native block editor meta box!
const BylinesRender = ( props ) => {
	return (
		<PluginDocumentSettingPanel
			name="bylines"
			title="Bylines"
			className="bylines-settings-panel"
		>
			<DndContext
				modifiers={ [ restrictToParentElement ] }
				onDragEnd={ props.onSortEnd }
			>
				<SortableContext
					items={ props.selectedBylines.map(
						( item ) => item.value
					) }
					strategy={ horizontalListSortingStrategy }
				>
					<AsyncSelect
						isMulti
						options={ props.options }
						loadOptions={ props.search }
						defaultOptions
						styles={ reactSelectStyles }
						value={ props.selectedBylines }
						onChange={ ( value ) => props.onBylineChange( value ) }
						cacheOptions
						closeMenuOnSelect
						noOptionsMessage={ () => 'No Bylines found...' }
						components={ {
							MultiValue,
							MultiValueRemove,
						} }
					/>
				</SortableContext>
			</DndContext>
		</PluginDocumentSettingPanel>
	);
};

const Bylines = compose( [
	withSelect( () => {
		const { getEditedPostAttribute } = select( 'core/editor' );
		const postMeta = getEditedPostAttribute( 'meta' );
		const options = [];
		const bylines = select( 'core' ).getEntityRecords(
			'bylines/v1',
			'bylines',
			{ per_page: -1 }
		);
		let selectedBylines = [];
		if ( postMeta && postMeta.bylines ) {
			selectedBylines = postMeta.bylines;
		}
		if ( bylines ) {
			bylines.forEach( ( byline ) => {
				options.push( {
					value: byline.value,
					label: byline.label,
				} );
			} );
		}
		if ( selectedBylines ) {
			selectedBylines.forEach( ( byline ) => {
				options.push( {
					value: byline.value,
					label: byline.label,
				} );
			} );
		}
		return {
			selectedBylines,
			options,
		};
	} ),
	withDispatch( () => {
		const { editPost } = dispatch( 'core/editor' );
		const { getEditedPostAttribute } = select( 'core/editor' );
		const postMeta = getEditedPostAttribute( 'meta' );
		let selectedBylines = [];
		if ( postMeta ) {
			selectedBylines = postMeta.bylines;
		}
		return {
			onBylineChange: ( value ) => {
				if ( value.length === 0 ) {
					value = null;
				}
				editPost( { meta: { bylines: value } } );
			},
			onSortEnd: ( event ) => {
				const { active, over } = event;

				if ( ! active || ! over ) return;

				const oldIndex = selectedBylines.findIndex(
					( item ) => item.value === active.id
				);
				const newIndex = selectedBylines.findIndex(
					( item ) => item.value === over.id
				);
				const newValue = arrayMove(
					selectedBylines,
					oldIndex,
					newIndex
				);
				editPost( { meta: { bylines: newValue } } );
			},
			search: ( value ) => {
				return apiFetch( {
					path: `/bylines/v1/bylines?per_page=100&s=${ value }`,
				} ).then( ( bylines ) => bylines );
			},
		};
	} ),
] )( BylinesRender );

registerPlugin( 'bylines', {
	render: Bylines,
	icon: '',
} );
