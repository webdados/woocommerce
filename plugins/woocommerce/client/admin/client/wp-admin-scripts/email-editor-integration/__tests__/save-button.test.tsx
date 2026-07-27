/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';
import type { ReactNode } from 'react';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { SaveButton } from '../save-button';

// ---- @wordpress/data mock -------------------------------------------------
//
// Drive the component's selectors from a mutable `state` object; each test
// sets the fields it cares about before rendering. Dispatchers are plain
// jest.fn()s asserted on directly.
const state = {
	isSaving: false,
	postStatus: 'auto-draft',
	isDirty: false,
	hasNonPostEntityChanges: false,
	dirtyEntityRecords: [] as {
		kind: string;
		name: string;
		key: string | number;
	}[],
	currentPostId: 5,
};

const mockEditPost = jest.fn();
const mockSavePost = jest.fn();
const mockSaveEditedEntityRecord = jest.fn();

const mockStoreSelect = ( store: unknown ) => {
	if ( store === editorStore ) {
		return {
			isSavingPost: () => state.isSaving,
			getEditedPostAttribute: ( attribute: string ) =>
				attribute === 'status' ? state.postStatus : undefined,
			isEditedPostDirty: () => state.isDirty,
			hasNonPostEntityChanges: () => state.hasNonPostEntityChanges,
			getCurrentPostId: () => state.currentPostId,
			getCurrentPostType: () => 'woo_email',
		};
	}
	return {
		__experimentalGetDirtyEntityRecords: () => state.dirtyEntityRecords,
	};
};

jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback: ( select: unknown ) => unknown ) =>
		callback( mockStoreSelect ),
	useDispatch: ( store: unknown ) =>
		store === jest.requireMock( '@wordpress/editor' ).store
			? { editPost: mockEditPost, savePost: mockSavePost }
			: { saveEditedEntityRecord: mockSaveEditedEntityRecord },
	select: ( store: unknown ) => mockStoreSelect( store ),
} ) );

jest.mock( '@wordpress/editor', () => ( {
	store: { name: 'core/editor' },
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		children,
		onClick,
		disabled,
	}: {
		children: ReactNode;
		onClick?: () => void;
		disabled?: boolean;
	} ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: { name: 'core' },
} ) );

describe( 'SaveButton', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		state.isSaving = false;
		state.postStatus = 'auto-draft';
		state.isDirty = false;
		state.hasNonPostEntityChanges = false;
		state.dirtyEntityRecords = [];
		state.currentPostId = 5;
	} );

	it( 'renders a button labeled "Save"', () => {
		render( <SaveButton /> );

		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).toBeInTheDocument();
	} );

	it( 'is enabled for an auto-draft post even when not dirty', () => {
		state.postStatus = 'auto-draft';
		state.isDirty = false;

		render( <SaveButton /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();
	} );

	it( 'is disabled when the post is published and not dirty', () => {
		state.postStatus = 'publish';
		state.isDirty = false;

		render( <SaveButton /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	it( 'publishes and saves an unpublished post on click', () => {
		state.postStatus = 'auto-draft';

		render( <SaveButton /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockEditPost ).toHaveBeenCalledWith( { status: 'publish' } );
		expect( mockSavePost ).toHaveBeenCalled();
	} );

	it( 'saves a published post on click without re-publishing it', () => {
		state.postStatus = 'publish';
		state.isDirty = true;

		render( <SaveButton /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockEditPost ).not.toHaveBeenCalled();
		expect( mockSavePost ).toHaveBeenCalled();
	} );

	it( 'is enabled for a published, non-dirty post when non-post entities have changes', () => {
		state.postStatus = 'publish';
		state.isDirty = false;
		state.hasNonPostEntityChanges = true;

		render( <SaveButton /> );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();
	} );

	it( 'saves dirty non-post entities on click but not the post entity record', () => {
		state.postStatus = 'publish';
		state.isDirty = true;
		state.currentPostId = 5;
		state.dirtyEntityRecords = [
			{ kind: 'postType', name: 'woo_email', key: 5 },
			{ kind: 'root', name: 'globalStyles', key: 1 },
		];

		render( <SaveButton /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockSaveEditedEntityRecord ).toHaveBeenCalledTimes( 1 );
		expect( mockSaveEditedEntityRecord ).toHaveBeenCalledWith(
			'root',
			'globalStyles',
			1,
			{}
		);
		expect( mockSaveEditedEntityRecord ).not.toHaveBeenCalledWith(
			'postType',
			'woo_email',
			5,
			{}
		);
	} );
} );
