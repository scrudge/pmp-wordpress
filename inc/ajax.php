<?php

include_once __DIR__ . '/class-sdkwrapper.php';

/**
 * Ajax search functionality
 *
 * @since 0.1
 */
function pmp_search() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$sdk = new SDKWrapper();
	$opts = array(
		'profile' => 'story',
		'limit' => 10
	);

	if (isset($_POST['query'])) {
		$query = json_decode(stripslashes($_POST['query']), true);
		$opts = array_merge($opts, $query);
	}

	if (isset($opts['guid'])) {
		$guid = $opts['guid'];
		unset($opts['guid']);
		$result = $sdk->query2json('fetchDoc', $guid, $opts);
	} else
		$result = $sdk->query2json('queryDocs', $opts);

	if (!$result) {
		header("HTTP/1.0 404 Not Found");
		print json_encode(array(
			"message" => "No results found.",
			"success" => false
		));
	} else {
		print json_encode(array(
			"data" => $result,
			"success" => true
		));
	}
	wp_die();
}
add_action('wp_ajax_pmp_search', 'pmp_search');

/**
 * Ajax function to create a draft post based on PMP story
 *
 * @since 0.1
 */
function pmp_draft_post() {
	check_ajax_referer('pmp_ajax_nonce', 'security');
	_pmp_ajax_create_post(true);
}
add_action('wp_ajax_pmp_draft_post', 'pmp_draft_post');

/**
 * Ajax function to publish a post based on PMP story
 *
 * @since 0.1
 */
function pmp_publish_post() {
	check_ajax_referer('pmp_ajax_nonce', 'security');
	_pmp_ajax_create_post();
}
add_action('wp_ajax_pmp_publish_post', 'pmp_publish_post');

function _pmp_ajax_create_post($draft=false) {
	print json_encode(_pmp_create_post($draft));
	wp_die();
}

function _pmp_create_post($draft=false) {
	if (!current_user_can('edit_posts'))
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );

	$data = json_decode(stripslashes($_POST['post_data']), true);

	$post_data = array(
		'post_title' => $data['attributes']['title'],
		'post_content' => $data['attributes']['contentencoded'],
		'post_excerpt' => $data['attributes']['teaser'],
		'post_author' => get_current_user_id(),
		'post_status' => (!empty($draft))? 'draft' : 'publish',
		'post_date' => date('Y-m-d H:i:s', strtotime($data['attributes']['published']))
	);

	$new_post = wp_insert_post($post_data);

	if (is_wp_error($new_post)) {
		return array(
			"success" => false,
			"message" => $new_post->get_error_message()
		);
	}

	if (!empty($data['attachment'])) {
		$attachment = $data['attachment'];

		$standard = null;

		// Try really hard to find the 'standard' image crop
		foreach ($attachment['links']['enclosure'] as $enc) {
			if ($enc['meta']['crop'] == 'standard') {
				$standard = $enc;
				break;
			}
		}

		// If we couldn't get the 'standard' crop, fallback to the first enclosure
		if (empty($standard) && !empty($attachment['links']['enclosure'][0]))
			$standard = $attachment['links']['enclosure'][0];

		// If we were able to get an enclosure proceed with attaching it to the post
		if (!empty($standard)) {
			$img_attrs = $attachment['attributes'];

			// Import the image
			$new_image = pmp_media_sideload_image(
				$standard['href'], $new_post, $img_attrs['description']);

			if (!is_wp_error($new_image)) {
				// If import was successful, set basic attachment attributes
				$image_update = array(
					'ID' => $new_image,
					'post_excerpt' => $img_attrs['description'], // caption
					'post_title' => $img_attrs['title']
				);
				wp_update_post($image_update);

				// Also set the alt text and various PMP-related attachment meta
				$image_meta= array(
					'_wp_attachment_image_alt' => $img_attrs['title'], // alt text
					'pmp_guid' => $img_attrs['guid'],
					'pmp_created' => $img_attrs['created'],
					'pmp_modified' => $img_attrs['modified'],
					'pmp_byline' => $img_attrs['byline'] // credit
				);

				foreach ($image_meta as $image_meta_key => $image_meta_value)
					update_post_meta($new_image, $image_meta_key, $image_meta_value);

				// Actually attach the image to the new post
				update_post_meta($new_post, '_thumbnail_id', $new_image);
			}
		}
	}

	$post_meta = array(
		'pmp_guid' => $data['attributes']['guid'],
		'pmp_created' => $data['attributes']['created'],
		'pmp_modified' => $data['attributes']['modified'],
		'pmp_byline' => $data['attributes']['byline'],
		'pmp_published' => $data['attributes']['published']
	);

	foreach ($post_meta as $key => $value)
		update_post_meta($new_post, $key, $value);

	return array(
		"success" => true,
		"data" => array(
			"edit_url" => html_entity_decode(get_edit_post_link($new_post))
		)
	);
}

/**
 * Ajax function to create new group
 *
 * @since 0.2
 */
function pmp_create_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));
	$sdk = new SDKWrapper();
	$doc = $sdk->newDoc('group', $group);

	$result = $doc->save();

	print json_encode(array("success" => true));
	wp_die();
}
add_action('wp_ajax_pmp_create_group', 'pmp_create_group');

/**
 * Ajax function to modify an existing group
 *
 * @since 0.2
 */
function pmp_modify_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));
	$sdk = new SDKWrapper();
	$doc = $sdk->fetchDoc(array('guid' => $group->attributes->guid));

	$items = $doc->items();
	$fetched = $items[0];
	$fetched->attributes = (object) array_merge((array) $fetched->attributes, (array) $group->attributes);

	$result = $fetched->save();

	print json_encode(array("success" => true));
	wp_die();
}
add_action('wp_ajax_pmp_modify_group', 'pmp_modify_group');

/**
 * Ajax function to modify an existing group
 *
 * @since 0.2
 */
function pmp_default_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));

	update_option('pmp_default_group', $group->attributes->guid);

	print json_encode(array("success" => true));
	wp_die();
}
add_action('wp_ajax_pmp_default_group', 'pmp_default_group');
