<?php

/**
 * A wrapper for PMP SDK with conveniences for the plugin
 *
 * @since 0.1
 */
class SDKWrapper {

	protected $sdk;

	public function __construct() {
		$settings = get_option('pmp_settings');

		$this->sdk = new \Pmp\Sdk(
			$settings['pmp_api_url'],
			$settings['pmp_client_id'],
			$settings['pmp_client_secret']
		);
	}

	public function __call($name, $args) {
		return call_user_func_array(array($this->sdk, $name), $args);
	}

	/**
	 * Convenience method cleans up query results data and returns serializable version for use with
	 * Backbone.js models and collections.
	 *
	 * Note: as a matter of convenience, this function merges `$item->attributes` with the top-level
	 * of each `$item` in the result set. That is, where the PMP SDK data structure resembles:
	 *
	 * $item
	 *   $attributes
	 *     $title
	 *     $contentencoded
	 *     $byline
	 *     ...
	 *   $links
	 *   $items
	 *
	 * This function will remove $attributes, producing a data structure that looks like:
	 *
	 * $item
	 *   $title
	 *   $contentencoded
	 *   $byline
	 *   ...
	 *   $links
	 *   $items
	 *
	 * @param $method (string) The query method to call (i.e., queryDocs or queryGroups, etc.)
	 * @param $arguments (array) The options to be pased to the query method.
	 *
	 * @since 0.1
	 */
	public function query2json($method, $args) {
		$result = $this->{$method}($args);

		if (empty($result)) {
			return $result;
		} else if (preg_match('/^fetch.*$/', $method)) {
			// There should only be 1 result when using `fetch*` methods
			$data = array(
				"total" => 1,
				"count" => 1,
				"page" => 1,
				"offset" => 0,
				"total_pages" => 1
			);

			$links = (array) $result->links;
			unset($links['auth']);
			unset($links['query']);

			$item = array(
				'attributes' => (array) $result->attributes,
				'links' => $links
			);

			$items = $result->items();
			if ($items) {
				foreach ($items as $related_item) {
					$related_links = (array) $related_item->links;
					unset($related_links['auth']);
					unset($related_links['query']);

					$item['items'][] = array(
						'links' => $related_links,
						'items' => (array) $related_item->items,
						'attributes' => (array) $related_item->attributes
					);
				}
			}

			$data['items'][] = $item;
		} else {
			$items = $result->items();
			$data = array(
				"total" => $result->items()->totalItems(),
				"count" => $result->items()->count(),
				"page" => $result->items()->pageNum(),
				"offset" => ($result->items()->pageNum() - 1) * $result->items()->count(),
				"total_pages" => $result->items()->totalPages()
			);

			if ($items) {
				foreach ($items as $item) {
					$links = (array) $item->links;

					unset($links['auth']);
					unset($links['query']);

					$data['items'][] = array(
						'links' => $links,
						'items' => (array) $item->items,
						'attributes' => (array) $item->attributes
					);
				}
			}
		}

		return $data;
	}

}
