<?php
/*
Plugin Name: Email Alerts
Plugin URI: http://wordpress.org/extend/plugins/email-alerts
Description: Allows users to configure whether they wish to be alerted when a comment is added, comment held, post posted.
Version: 1.2
Author: Simon Wheatley
Author URI: http://simonwheatley.co.uk/wordpress/
*/

// SWTODO: Check we don't retain user capabilities if they are downgraded. On save?

require (dirname (__FILE__).'/plugin.php');

class EmailAlerts extends EmailAlerts_Plugin
{
	var $post;
	var $comment;
	var $subscriber_ids;

	function EmailAlerts() {
		$this->register_plugin ('email-alerts', __FILE__);
		// Listen for comment and post status changes
		$this->add_action( 'wp_set_comment_status', null, null, 2 );
		$this->add_action( 'comment_post', null, null, 2 );
		$this->add_action( 'transition_post_status', null, null, 3 );
		$this->add_action( 'save_post', null, null, 1 );
		// Let the user subscribe to the various notifications
		$this->add_action( 'show_user_profile', 'user_profile' );
		$this->add_action( 'edit_user_profile', 'user_profile' );
		$this->add_action( 'profile_update' );		
	}

	// Hooks
	// =====

	// Notify everyone on the appropriate notification list 
	// SWTODO: Except the post author (if the option 'comments_notify' is specified) as in that situation, the post author will be notified separately.
	// Status can be 'hold', 'approve', 'spam' or 'delete'.
	function wp_set_comment_status( $comment_id, $comment_status ) {
		// Work out the subscription we should be using
		switch ( $comment_status ) {
			case 'approve':
				$subscription = 'approve_comment';
				break;
			case 'hold':
				$subscription = 'hold_comment';
				break;
		}
		// We're only bothered about things we have a subscription for.
		if ( ! isset( $subscription ) )
			return;
		// Get the subscribers
		$this->subscriber_ids = $this->get_subscriber_ids( $subscription );
		// Store the comment, for access by other methods
		$this->comment = & get_comment( $comment_id );
		// Similarly, store the post
		$this->post = & get_post( $this->comment->comment_post_ID );
		// All ready, send the notifications
		$this->send_notifications( $subscription );
	}
	
	// Called when a comment is added
	// Comment approved status will be: 1, 0 or 'spam' as returned from wp_allow_comment
	function comment_post( $comment_id, $comment_approved_status ) {
		// Don't do anything if we get a spam comment
		if ( $comment_approved_status === 'spam' )
			return null;

		$subscription = 'hold_comment';
		if ( $comment_approved_status )
			$subscription = 'approve_comment';
			
		// Get the subscribers
		$this->subscriber_ids = $this->get_subscriber_ids( $subscription );
		// Store the comment, for access by other methods
		$this->comment = & get_comment( $comment_id );
		// Similarly, store the post
		$this->post = & get_post( $this->comment->comment_post_ID );
		// All ready, send the notifications
		$this->send_notifications( $subscription );
	}

	// If the new status is "publish", and the old status wasn't "publish", then call $this->post_published();
	function transition_post_status( $new_status, $old_status, $post ) {
		$subscription = false;
		if ( $new_status == 'publish' && $old_status != 'publish' )
			$subscription = 'publish_post';
		if ( $new_status == 'pending' && $old_status != 'pending' )
			$subscription = 'pending_post';
		// We're only bothered about things we have a subscription for.
		if ( ! $subscription )
			return;
		// Get the subscribers
		$this->subscriber_ids = $this->get_subscriber_ids( $subscription );
		// Store the post
		$this->post = & $post;
		// All ready, send the notifications
		$this->send_notifications( $subscription );
	}

	function save_post( $post_id ) {
		// Get the post object from the ID
		$post = get_post( $post_id );
		
		// Since this action hook is called twice during a normal save (once for the post and once for
		// the revision), we check that we only email out on the actual post save.
		if ( $post->post_type == 'revision' )
			return;
			
		$subscription = 'edit_post';
		// Get the subscribers
		$this->subscriber_ids = $this->get_subscriber_ids( $subscription );
		// Store the post (this action hook only takes the post ID, so we need to fetch the post object)
		$this->post = & $post;
		// All ready, send the notifications
		$this->send_notifications( $subscription );
	}

	function user_profile() {
		global $profileuser;
		$vars = array();
		// Can only subscribe to "publish post" notifications if you can publish posts
		$vars[ 'can_sub_publish_post' ] = ( $profileuser->has_cap( 'publish_posts' ) ) ? true : false;
		$vars[ 'can_sub_pending_post' ] = ( $profileuser->has_cap( 'publish_posts' ) ) ? true : false;
		// Can only subscribe to "edit post" notifications if you can edit posts
		$vars[ 'can_sub_edit_post' ] = ( $profileuser->has_cap( 'edit_posts' ) ) ? true : false;
		// Can only subscribe to "hold comment" notifications if you can moderate comments
		// Can only subscribe to "approve comment" notifications if you can moderate comments
		$vars[ 'can_sub_hold_comment' ] = ( $profileuser->has_cap( 'moderate_comments' ) ) ? true : false;
		$vars[ 'can_sub_approve_comment' ] = ( $profileuser->has_cap( 'moderate_comments' ) ) ? true : false;
		// Any permissions at all? If not, then don't do anything.
		if ( empty( $vars) )
			return;
		// Get all the subscription stuff
		$vars[ 'checked_publish_post' ] = $this->get_subscription( 'publish_post' );
		$vars[ 'checked_pending_post' ] = $this->get_subscription( 'pending_post' );
		$vars[ 'checked_edit_post' ] = $this->get_subscription( 'edit_post' );
		$vars[ 'checked_hold_comment' ] = $this->get_subscription( 'hold_comment' );
		$vars[ 'checked_approve_comment' ] = $this->get_subscription( 'approve_comment' );
		$this->render_admin( 'user_profile', $vars );
	}

	function profile_update() {
		// If the checkboxes aren't present, then the subscription will be 
		// removed. This is a Good Thing™.
		$this->set_subscription( 'publish_post' );
		$this->set_subscription( 'pending_post' );
		$this->set_subscription( 'edit_post' );
		$this->set_subscription( 'hold_comment' );
		$this->set_subscription( 'approve_comment' );
	}
	// Utilities
	// =========

	function send_notifications( $subscription ) {
		// Call a method named simiarly to $this->template_vars_approve_comment() for
		// the vars specific to this template
		$vars = call_user_func_array( array( &$this, 'vars_' . $subscription ), array() );
		// Vars generic to all templates
		// Blog name
		$vars[ 'blog_name' ] = get_bloginfo( 'name' );
		// Post
		$vars[ 'post' ] = & $this->post;
		// Current user (so we can avoid sending notifications to the person who has just done the deed)
		$current_user = wp_get_current_user();
		foreach( $this->subscriber_ids AS $subscriber_id )
		{
			// Only run the alert for users who are not the person logged in
			if ( $subscriber_id != $current_user->ID ) { 
				$subscriber = new WP_User( $subscriber_id );
				// Subscriber name
				$vars[ 'subscriber_name' ] = $subscriber->display_name;
				$this->send_notification( $subscriber, $subscription, $vars );
				unset( $subscriber );
			}
		}
	}

	function send_notification( & $subscriber, $subscription, & $vars ) {
		// Use an admin template for the text of the email, see [plugin_dir]/view/admin/msg_*
		$body = $this->capture_admin( 'msg_' . $subscription , $vars );
		// Subject
		$subject = call_user_func_array( array( &$this, 'subject_' . $subscription ), array( $vars ) );
		// Headers
		$headers = "MIME-Version: 1.0\n"
			. "Content-Type: text/html; charset=\"" . get_option('blog_charset') . "\"\n";
		// Last but not least, send the email
		@ wp_mail( $subscriber->user_email, $subject, $body, $headers);
	}

	function vars_approve_comment() {
		return $this->vars_comment();
	}

	function vars_hold_comment() {
		return $this->vars_comment();
	}
	
	function vars_comment() {
		$vars = array();
		// Comment
		$vars[ 'comment' ] = & $this->comment;
		// Comment type
		$vars[ 'comment_type' ] = empty( $this->comment->comment_type ) ? "comment" : $this->comment->comment_type;
		// Comment author name
		if ( empty($this->comment->comment_author) )
			$author = __('Anonymous');
		else
			$author = $this->comment->comment_author;
		$vars[ 'comment_author_name' ] = $author;
		// Comment author email
		$vars[ 'comment_author_email' ] = $this->comment->comment_author_email;
		// Comment URL
		$vars[ 'comment_url' ] = get_permalink( $this->comment->comment_post_ID ) . '#comment-' . $this->comment->comment_ID;
		// Comment author domain
		$vars[ 'comment_author_domain' ] = @ gethostbyaddr($this->comment->comment_author_IP);
		// Comment as HTML
		// This uses a simpler approach than wp_autop.
		$vars[ 'comment_as_html' ] = str_replace( "\r\n", "<br />", $this->comment->comment_content );
		// Did an admin perform this action?
		if ( current_user_can( 'moderate_comments' ) ) {
			$current_user = wp_get_current_user();
			$vars[ 'acting_admin_name' ] = $current_user->display_name;
			$vars[ 'acting_admin_email' ] = $current_user->user_email;
		}
		return $vars;
	}

	function vars_edit_post() {
		$vars = array();
		$vars[ 'post_edit_link' ] = get_edit_post_link( $this->post->ID );
		$post_author = new WP_User( $this->post->post_author );
		$vars[ 'post_author_name' ] = $post_author->display_name;
		$post_edited_by = wp_get_current_user();
		$vars[ 'blog_name' ] = get_bloginfo( 'name' );
		$vars[ 'post_edited_by' ] = $post_edited_by->display_name;
		return $vars;
	}

	function vars_pending_post() {
		$vars = array();
		// N.B. The get_edit_post_link function will check the caps of the
		// actual current user, but they should be able to edit the post
		// so that should be fine!
		$vars[ 'post_edit_link' ] = get_edit_post_link( $this->post->ID );
		$post_author = new WP_User( $this->post->post_author );
		$vars[ 'post_author_name' ] = $post_author->display_name;
		$vars[ 'blog_name' ] = get_bloginfo( 'name' );
		return $vars;
	}

	function vars_publish_post() {
		$vars = array();
		$vars[ 'post_permalink' ] = get_permalink( $this->post->ID );
		$post_author = new WP_User( $this->post->post_author );
		$vars[ 'post_author_name' ] = $post_author->display_name;
		$vars[ 'post_author_email' ] = $post_author->user_email;
		$current_user = wp_get_current_user();
		$vars[ 'acting_admin_name' ] = $current_user->display_name;
		$vars[ 'acting_admin_email' ] = $current_user->user_email;
		$vars[ 'blog_name' ] = get_bloginfo( 'name' );
		return $vars;
	}

	function subject_approve_comment( & $vars ) {
		return sprintf( __( '%1$s: Comment approved', 'email-alerts' ), $vars[ 'blog_name' ] );
	}

	function subject_hold_comment( & $vars ) {
		return sprintf( __( '%1$s: Comment awaiting approval', 'email-alerts' ), $vars[ 'blog_name' ] );
	}

	function subject_pending_post( & $vars ) {
		return sprintf( __( '%1$s: Post pending review', 'email-alerts' ), $vars[ 'blog_name' ] );
	}

	function subject_publish_post( & $vars ) {
		return sprintf( __( '%1$s: Post published', 'email-alerts' ), $vars[ 'blog_name' ] );
	}

	function subject_edit_post( & $vars ) {
		return sprintf( __( '%1$s: Post edited', 'email-alerts' ), $vars[ 'blog_name' ] );
	}

	function get_subscription( $subscription ) {
		global $profileuser;
		$meta_key = 'ea_sub_' . $subscription;
		return (bool) $profileuser->$meta_key;
	}

	function set_subscription( $subscription ) {
		$user_id = (int) @ $_REQUEST['user_id'];
		$meta_value = (bool) @ $_POST[ 'ea_sub_' . $subscription ];
		update_user_meta( $user_id, 'ea_sub_' . $subscription, $meta_value );
	}
	
	function get_subscriber_ids( $subscription ) {
		global $wpdb;

		$meta_key = 'ea_sub_' . $subscription;
		$sql = $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value = 1", $meta_key );
		$user_ids = $wpdb->get_col( $sql );

		return (array) $user_ids;
	}

	function some_ea_fields_present() {
		return (bool) @ $_POST[ 'ea_present' ];
	}
}

/**
 * Instantiate the plugin
 *
 **/

$email_alerts = new EmailAlerts;

