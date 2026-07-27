/**
 * External dependencies
 */
import { useState, useMemo, useEffect } from '@wordpress/element';
import { pencil, external } from '@wordpress/icons';
import { Icon, Notice, Spinner } from '@wordpress/components';
import { getAdminLink } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
// @ts-expect-error - We need to use this /wp see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/#dataviews
import { DataViews, View } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { EmailType } from './settings-email-listing-slotfill';
import { useTransactionalEmails } from './settings-email-listing-data';
import { shouldShowReviewUpdate } from './settings-email-listing-update-state';
import { Status, EMAIL_STATUSES } from './settings-email-listing-status';
import { RecipientsList } from './settings-email-listing-recipients';
import { UpdatesCell } from './settings-email-listing-update-cell';
import {
	SendTestEmailForm,
	useSendTestEmail,
} from './settings-email-send-test';

const SendTestEmailModalContent = ( {
	postId,
	emailClassName,
	onClose,
}: {
	postId: number;
	emailClassName: string;
	onClose: () => void;
} ) => {
	const { email, setEmail, isSending, notice, noticeType, sendEmail } =
		useSendTestEmail(
			{ endpoint: 'editor', postId, emailType: emailClassName },
			'email_listing'
		);

	return (
		<SendTestEmailForm
			email={ email }
			onEmailChange={ setEmail }
			isSending={ isSending }
			notice={ notice }
			noticeType={ noticeType }
			onSend={ sendEmail }
			onCancel={ onClose }
		/>
	);
};

/**
 * Resolves the post backing the email before showing the send-test form.
 * With lazy post creation most emails have no post until edited — an
 * auto-draft with the file template content is created on demand so the test
 * email matches what customers receive.
 */
const LazySendTestEmailModalContent = ( {
	email,
	recreateEmailPost,
	onClose,
}: {
	email: EmailType;
	recreateEmailPost: (
		emailId: string
	) => Promise< { post_id: string } | null >;
	onClose: () => void;
} ) => {
	const initialPostId = parseInt( email.post_id, 10 );
	const [ resolvedPostId, setResolvedPostId ] = useState< number | null >(
		Number.isFinite( initialPostId ) ? initialPostId : null
	);
	const [ hasError, setHasError ] = useState( false );

	useEffect( () => {
		if ( resolvedPostId !== null ) {
			return;
		}
		void recreateEmailPost( email.id ).then( ( response ) => {
			const newPostId = parseInt( response?.post_id ?? '', 10 );
			if ( Number.isFinite( newPostId ) ) {
				setResolvedPostId( newPostId );
			} else {
				setHasError( true );
			}
		} );
		// Runs once on mount — the modal is remounted per row.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( hasError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Could not prepare the email for sending a test. Please try again.',
					'woocommerce'
				) }
			</Notice>
		);
	}

	if ( resolvedPostId === null ) {
		return <Spinner />;
	}

	return (
		<SendTestEmailModalContent
			postId={ resolvedPostId }
			emailClassName={ email.email_class_name }
			onClose={ onClose }
		/>
	);
};

export const ListView = ( { emailTypes }: { emailTypes: EmailType[] } ) => {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		search: '',
		fields: [ 'recipients', 'status', 'updates' ],
		filters: [],
		page: 1,
		perPage: 20,
		titleField: 'title',
		showTitle: true,
		layout: {},
	} );

	const { emails, total, updateEmailEnabledStatus, recreateEmailPost } =
		useTransactionalEmails( emailTypes, view );

	const fields = useMemo( () => {
		const recipientElements = Array.from(
			emailTypes.reduce( ( acc, email ) => {
				const recipients = [
					...( email.recipients.to
						? email.recipients.to
								.split( ',' )
								.map( ( r ) => r.trim() )
								.filter( Boolean )
						: [] ),
					...( email.recipients.cc
						? email.recipients.cc
								.split( ',' )
								.map( ( r ) => r.trim() )
								.filter( Boolean )
						: [] ),
					...( email.recipients.bcc
						? email.recipients.bcc
								.split( ',' )
								.map( ( r ) => r.trim() )
								.filter( Boolean )
						: [] ),
				];
				recipients.forEach( ( recipient ) => acc.add( recipient ) );
				return acc;
			}, new Set< string >() )
		).map( ( recipient ) => ( { value: recipient, label: recipient } ) );

		return [
			{
				id: 'title',
				label: __( 'Title', 'woocommerce' ),
				enableHiding: false,
				render: ( row: { item: EmailType } ) => {
					return (
						<div className="woocommerce-email-listing-title">
							{ row.item.title }
							<br />
							<span className="woocommerce-email-listing-description">
								{ row.item.description }
							</span>
						</div>
					);
				},
			},
			{
				id: 'recipients',
				label: __( 'Recipient(s)', 'woocommerce' ),
				enableHiding: true,
				filterBy: {
					operators: [ 'isAny' ],
				},
				elements: recipientElements,
				render: ( row: { item: EmailType } ) => {
					return (
						<RecipientsList recipients={ row.item.recipients } />
					);
				},
			},
			{
				id: 'status',
				label: __( 'Status', 'woocommerce' ),
				enableHiding: true,
				filterBy: {
					operators: [ 'isAny' ],
				},
				render: ( row: { item: EmailType } ) => {
					return <Status slug={ row.item.status } />;
				},
				elements: EMAIL_STATUSES,
			},
			{
				id: 'updates',
				label: __( 'Updates', 'woocommerce' ),
				enableHiding: true,
				enableSorting: false,
				getValue: ( { item }: { item: EmailType } ) =>
					shouldShowReviewUpdate( item ) ? 'available' : 'none',
				elements: [
					{
						value: 'available',
						label: __( 'Update available', 'woocommerce' ),
					},
					{
						value: 'none',
						label: __( 'Up to date', 'woocommerce' ),
					},
				],
				filterBy: {
					operators: [ 'is' ],
					isPrimary: true,
				},
				render: ( { item }: { item: EmailType } ) => (
					<UpdatesCell post={ item } />
				),
			},
		];
	}, [ emailTypes ] );

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'woocommerce' ),
				icon: <Icon icon={ pencil } />,
				supportsBulk: false,
				callback: async ( items: EmailType[] ) => {
					const email = items[ 0 ];
					if ( email.post_id ) {
						window.location.href = getAdminLink(
							`post.php?post=${ encodeURIComponent(
								email.post_id
							) }&action=edit`
						);
						return;
					}
					// Lazily create the post (an auto-draft with the file
					// template content) and open it in the editor.
					const response = await recreateEmailPost( email.id );
					if ( response?.post_id ) {
						window.location.href = getAdminLink(
							`post.php?post=${ encodeURIComponent(
								response.post_id
							) }&action=edit`
						);
					}
				},
			},
			{
				id: 'preview',
				label: __( 'Preview', 'woocommerce' ),
				icon: <Icon icon={ external } />,
				supportsBulk: false,
				callback: ( items: EmailType[] ) => {
					window.open( items[ 0 ].link );
				},
				// The permalink only renders saved content, so previewing is
				// limited to published posts (unpublished drafts are not what
				// customers receive; emails without a post render from the
				// file template and have no permalink).
				isEligible: ( item: EmailType ) =>
					!! item.post_id && item.postStatus === 'publish',
				isPrimary: true,
			},
			{
				id: 'test',
				label: __( 'Send test email', 'woocommerce' ),
				supportsBulk: false,
				modalHeader: __( 'Send a test email', 'woocommerce' ),
				RenderModal: ( {
					items,
					closeModal,
				}: {
					items: EmailType[];
					closeModal?: () => void;
				} ) => (
					<LazySendTestEmailModalContent
						email={ items[ 0 ] }
						recreateEmailPost={ recreateEmailPost }
						onClose={ closeModal ?? ( () => {} ) }
					/>
				),
			},
			{
				id: 'change-status',
				label: ( items: EmailType[] ) =>
					items[ 0 ].status === 'enabled'
						? __( 'Deactivate email', 'woocommerce' )
						: __( 'Activate email', 'woocommerce' ),
				supportsBulk: false,
				isEligible: ( item: EmailType ) =>
					item.status === 'enabled' || item.status === 'disabled',
				callback: ( items: EmailType[] ) => {
					void updateEmailEnabledStatus(
						items[ 0 ].id,
						! items[ 0 ].enabled
					);
				},
			},
		],
		[ updateEmailEnabledStatus, recreateEmailPost ]
	);

	const form = {
		type: 'panel',
		fields: [ 'title' ],
	};

	return (
		<DataViews
			view={ view }
			form={ form }
			actions={ actions }
			onChangeView={ setView }
			fields={ fields }
			data={ emails ?? [] }
			paginationInfo={ {
				totalItems: total,
				totalPages: Math.ceil( total / view.perPage ),
			} }
			defaultLayouts={ {
				table: {
					showMedia: false,
				},
			} }
			showLayoutSwitcher={ false }
			getItemId={ ( item: EmailType ) =>
				`${ item.id }_${ item?.email_key || '' }`
			}
		/>
	);
};
