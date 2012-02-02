<?php
/*
Plugin Name: Extend XMLRPC
Plugin Author: William P. Davis, Bangor Daily News
*/


/*
Usage:
You can use the structure below to create and add your own xmlrpc methods, or you can use the function below yourself (it's pretty powerful)


Some example arrays:
array( 1, $username, $password, $post_type, $category, $numberposts, $extra );

Get 10 most recently published posts:
array( 1, $username, $password, 'post', false, 10, false );

Get 10 most recently modified posts
array( 1, $username, $password, 'post', false, 10, array( 'orderby' => 'modified' ) );

Get 10 most recently modified posts in the sports category ($category can be either a slug or an ID)
array( 1, $username, $password, 'post', 'sports', 10, array( 'orderby' => 'modified' ) );

Get 10 most recently modified posts or pages
array( 1, $username, $password, array( 'post', 'page' ), false, 10, array( 'orderby' => 'modified' ) );

Search for posts in the sports category with the term Tom Brady in it
array( 1, $username, $password, 'post', 'sports', 10, array( 's' => 'Tom Brady' ) );

Basically, it accepts anything get_posts accepts (http://codex.wordpress.org/Function_Reference/query_posts#Parameters)

*/

add_filter( 'xmlrpc_methods', 'add_my_xmlrpc_methods' );

function add_my_xmlrpc_methods( $methods ) {
    $methods['bdn.getPosts'] = 'bdn_xmlrpc_get_posts';
    return $methods;
}

function bdn_xmlrpc_get_posts( $args ) {
		global $wp_xmlrpc_server;
		$wp_xmlrpc_server->escape( $args );

		$blog_ID     = (int) $args[0];
		$username  = $args[1];
		$password   = $args[2];
		$post_type  = $args[3];
		$category = $args[4];
		$numberposts = $args[5];
		$extra = $args[6];

		if ( !$user = $wp_xmlrpc_server->login($username, $password) ) {
			return $wp_xmlrpc_server->error;
		}
		
		$category_int = (int) $category;
		
		if( !empty( $category_int ) ) {
			$category_call = 'cat';
		} else {
			$category_call = 'category_name';
		}

		$post_args = array(
				'numberposts' => $numberposts,
				'posts_per_page' => $numberposts,
				$category_call => $category,
				'post_type' => $post_type,
				'post_status' => 'any'
			);
		if( is_array( $extra ) )
			$post_args = array_merge( $post_args, $extra );
		
		$posts_list = query_posts( $post_args );
		
		if ( !$posts_list ) {
			$wp_xmlrpc_server->error = new IXR_Error(500, __('Either there are no posts, or something went wrong.'));
			return $wp_xmlrpc_server->error;
		}

		foreach ($posts_list as $entry) {
			if ( !current_user_can( 'edit_post', $entry->ID ) )
				continue;

			$post_date = mysql2date('Ymd\TH:i:s', $entry->post_date, false);
			$post_date_gmt = mysql2date('Ymd\TH:i:s', $entry->post_date_gmt, false);
			$post_modified = mysql2date('Ymd\TH:i:s', $entry->post_modified, false);

			// For drafts use the GMT version of the date
			if ( $entry->post_status == 'draft' )
				$post_date_gmt = get_gmt_from_date( mysql2date( 'Y-m-d H:i:s', $entry->post_date ), 'Ymd\TH:i:s' );

			$categories = array();
			$cats = get_the_category( $entry->ID, 'category' );
			foreach( $cats as $cat )
				$categories[] = array( 'name' => $cat->cat_name, 'parent' => $cat->category_parent );
			
			$publications = array();
			$pubs = get_the_terms( $entry->ID, 'publication' );
			foreach( $pubs as $pub )
				$publications[] = $pub->name;

			$post = get_extended( $entry->post_content );
			$link = post_permalink( $entry->ID );

			// Get the post author info.
			$authors = (array) get_userdata( $entry->post_author );
			
			if( function_exists( 'get_coauthors' ) )
				$authors = get_coauthors( $entry->ID );

			$allow_comments = ( 'open' == $entry->comment_status ) ? 1 : 0;
			$allow_pings = ( 'open' == $entry->ping_status ) ? 1 : 0;

			// Consider future posts as published
			if ( $entry->post_status === 'future' )
				$entry->post_status = 'publish';

			$struct[] = array(
				'dateCreated' => new IXR_Date( $post_date ),
				'dateModified' => new IXR_Date( $post_modified ),
				'userid' => $entry->post_author,
				'postid' => $entry->ID,
				'description' => $post['main'],
				'title' => $entry->post_title,
				'link' => $link,
				'permaLink' => $link,
				// commented out because no other tool seems to use this
				// 'content' => $entry['post_content'],
				'categories' => $categories,
				'mt_excerpt' => $entry->post_excerpt,
				'mt_text_more' => $post['extended'],
				'mt_allow_comments' => $allow_comments,
				'mt_allow_pings' => $allow_pings,
				'wp_slug' => $entry->post_name,
				'wp_password' => $entry->post_password,
				'wp_author_id' => $author->ID,
				'wp_authors' => $authors,
				'date_created_gmt' => new IXR_Date($post_date_gmt),
				'post_status' => $entry->post_status,
				'custom_fields' => $wp_xmlrpc_server->get_custom_fields($entry->ID),
				'publications' => $publications,
			);

		}

		$recent_posts = array();
		for ( $j=0; $j<count($struct); $j++ ) {
			array_push($recent_posts, $struct[$j]);
		}

		return $recent_posts;

}