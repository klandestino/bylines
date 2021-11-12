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
import { SortableContainer, SortableElement } from 'react-sortable-hoc';
import reactSelectStyles from 'gutenberg-react-select-styles';
const { isSavingPost } = select( 'core/editor' );
const SortableSelect = SortableContainer( AsyncSelect );
const sortableMultiValue = SortableElement( ( props ) => {
	const onMouseDown = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
	};
	const innerProps = { onMouseDown };
	return <components.MultiValue { ...props } innerProps={ innerProps } />;
} );

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
		<PluginDocumentSettingPanel name="bylines" title="Bylines">
			<SortableSelect
				axis="xy"
				onSortEnd={ props.onSortEnd }
				distance={ 4 }
				getHelperDimensions={ ( { node } ) =>
					node.getBoundingClientRect()
				}
				isMulti
				value={ props.selectedBylines }
				onChange={ ( value ) => props.onBylineChange( value ) }
				components={ { MultiValue: sortableMultiValue } }
				closeMenuOnSelect={ true }
				cacheOptions
				defaultOptions={ props.options }
				loadOptions={ props.search }
				styles={ reactSelectStyles }
			/>
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
		const arrayMove = ( array, from, to ) => {
			array = array.slice();
			array.splice(
				to < 0 ? array.length + to : to,
				0,
				array.splice( from, 1 )[ 0 ]
			);
			return array;
		};
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
			onSortEnd: ( { oldIndex, newIndex } ) => {
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
