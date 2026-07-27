/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { ListView } from '../settings-email-listing-listview';
import type { EmailType } from '../settings-email-listing-slotfill';
import { useSendTestEmail } from '../settings-email-send-test';

type DataViewsAction = {
	id: string;
	callback?: ( items: EmailType[] ) => void | Promise< void >;
	isEligible?: ( item: EmailType ) => boolean;
	RenderModal?: ComponentType< {
		items: EmailType[];
		closeModal?: () => void;
	} >;
};

// Captured on each render so tests can drive the actions directly.
let capturedActions: DataViewsAction[] = [];

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( { actions }: { actions: DataViewsAction[] } ) => {
		capturedActions = actions;
		return null;
	},
} ) );

const mockRecreateEmailPost = jest.fn();

jest.mock( '../settings-email-listing-data', () => ( {
	useTransactionalEmails: ( emailTypes: EmailType[] ) => ( {
		emails: emailTypes,
		total: emailTypes.length,
		updateEmailEnabledStatus: jest.fn(),
		recreateEmailPost: mockRecreateEmailPost,
	} ),
} ) );

jest.mock( '@woocommerce/settings', () => ( {
	getAdminLink: ( path: string ) => `https://example.test/wp-admin/${ path }`,
} ) );

jest.mock( '../settings-email-send-test', () => ( {
	useSendTestEmail: jest.fn( () => ( {
		email: '',
		setEmail: jest.fn(),
		isSending: false,
		notice: '',
		noticeType: '',
		sendEmail: jest.fn(),
	} ) ),
	SendTestEmailForm: () => null,
} ) );

const useSendTestEmailMock = useSendTestEmail as jest.MockedFunction<
	typeof useSendTestEmail
>;

const emailType: EmailType = {
	title: 'New order',
	description: 'New order notification',
	id: 'new_order',
	email_key: 'wc_email_new_order',
	email_class_name: 'WC_Email_New_Order',
	post_id: '123',
	recipients: {
		to: 'admin@example.com',
		cc: '',
		bcc: '',
	},
	enabled: true,
	manual: false,
	postStatus: 'publish',
	templateStatus: null,
	templateVersion: null,
	currentVersion: null,
	wasBackfilled: false,
};

const getAction = ( id: string ) =>
	capturedActions.find( ( action ) => action.id === id );

const renderTestModal = ( email: EmailType ) => {
	render( <ListView emailTypes={ [ email ] } /> );
	const RenderModal = getAction( 'test' )?.RenderModal;
	if ( ! RenderModal ) {
		throw new Error( 'Send test email action has no RenderModal' );
	}
	return render(
		<RenderModal items={ [ email ] } closeModal={ () => {} } />
	);
};

describe( 'ListView', () => {
	let originalLocation: typeof window.location;

	beforeEach( () => {
		capturedActions = [];
		useSendTestEmailMock.mockClear();
		mockRecreateEmailPost.mockReset();

		originalLocation = window.location;
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		delete ( window as any ).location;
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		( window as any ).location = {
			...originalLocation,
			href: '',
			assign: jest.fn(),
		};
	} );

	afterEach( () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		( window as any ).location = originalLocation;
	} );

	describe( 'edit action', () => {
		it( 'navigates directly to the post editor when a post already exists', async () => {
			render( <ListView emailTypes={ [ emailType ] } /> );

			await getAction( 'edit' )?.callback?.( [ emailType ] );

			expect( mockRecreateEmailPost ).not.toHaveBeenCalled();
			expect( window.location.href ).toBe(
				'https://example.test/wp-admin/post.php?post=123&action=edit'
			);
		} );

		it( 'lazily creates the post and navigates to it when no post exists', async () => {
			mockRecreateEmailPost.mockResolvedValue( {
				message: 'ok',
				post_id: '456',
			} );
			const emailWithoutPost = {
				...emailType,
				post_id: '',
				postStatus: null,
			};
			render( <ListView emailTypes={ [ emailWithoutPost ] } /> );

			await getAction( 'edit' )?.callback?.( [ emailWithoutPost ] );

			expect( mockRecreateEmailPost ).toHaveBeenCalledWith( 'new_order' );
			expect( window.location.href ).toBe(
				'https://example.test/wp-admin/post.php?post=456&action=edit'
			);
		} );

		it( 'does not navigate when the post could not be created', async () => {
			mockRecreateEmailPost.mockResolvedValue( null );
			const emailWithoutPost = {
				...emailType,
				post_id: '',
				postStatus: null,
			};
			render( <ListView emailTypes={ [ emailWithoutPost ] } /> );

			await getAction( 'edit' )?.callback?.( [ emailWithoutPost ] );

			expect( mockRecreateEmailPost ).toHaveBeenCalledWith( 'new_order' );
			expect( window.location.href ).toBe( '' );
		} );
	} );

	it( 'does not register a recreate-email-post action', () => {
		render( <ListView emailTypes={ [ emailType ] } /> );

		expect( getAction( 'recreate-email-post' ) ).toBeUndefined();
	} );

	describe( 'preview action eligibility', () => {
		it( 'is eligible only for published posts', () => {
			render( <ListView emailTypes={ [ emailType ] } /> );
			const isEligible = getAction( 'preview' )?.isEligible;

			expect(
				isEligible?.( { ...emailType, postStatus: 'publish' } )
			).toBe( true );
			expect(
				isEligible?.( { ...emailType, postStatus: 'draft' } )
			).toBe( false );
			expect(
				isEligible?.( { ...emailType, postStatus: 'auto-draft' } )
			).toBe( false );
			expect(
				isEligible?.( { ...emailType, post_id: '', postStatus: null } )
			).toBe( false );
		} );
	} );

	describe( 'send test email action', () => {
		it( 'uses the email class name when tracking a test send', () => {
			renderTestModal( emailType );

			expect( useSendTestEmailMock ).toHaveBeenCalledWith(
				{
					endpoint: 'editor',
					postId: 123,
					emailType: 'WC_Email_New_Order',
				},
				'email_listing'
			);
		} );

		it( 'is available for emails without a post', () => {
			render( <ListView emailTypes={ [ emailType ] } /> );
			const action = getAction( 'test' );

			// No eligibility gate — every email can send a test; the post is
			// resolved lazily when the modal opens.
			expect( action ).toBeDefined();
			expect( action?.isEligible ).toBeUndefined();
		} );

		it( 'lazily creates the post before rendering the send form when no post exists', async () => {
			mockRecreateEmailPost.mockResolvedValue( {
				message: 'ok',
				post_id: '789',
			} );
			const emailWithoutPost = {
				...emailType,
				post_id: '',
				postStatus: null,
			};

			renderTestModal( emailWithoutPost );

			expect( mockRecreateEmailPost ).toHaveBeenCalledWith( 'new_order' );
			await waitFor( () => {
				expect( useSendTestEmailMock ).toHaveBeenCalledWith(
					{
						endpoint: 'editor',
						postId: 789,
						emailType: 'WC_Email_New_Order',
					},
					'email_listing'
				);
			} );
		} );

		it( 'shows an error when the post could not be created', async () => {
			mockRecreateEmailPost.mockResolvedValue( null );
			const emailWithoutPost = {
				...emailType,
				post_id: '',
				postStatus: null,
			};

			const { findAllByText } = renderTestModal( emailWithoutPost );

			// The Notice renders the message plus an a11y live region copy.
			const notices = await findAllByText(
				'Could not prepare the email for sending a test. Please try again.'
			);
			expect( notices.length ).toBeGreaterThan( 0 );
			expect( useSendTestEmailMock ).not.toHaveBeenCalled();
		} );
	} );
} );
