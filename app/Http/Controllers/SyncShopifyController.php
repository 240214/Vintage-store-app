<?php
/**
 * NOT USED
 * NEED TO REMOVE
 */

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Settings;
use App\Models\Variant;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Shopify\MyShopify;

class SyncShopifyController extends Controller {
	use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

	private $limit_max = 250;
	private $limit_partials = 250;
	private $shopifyApi;
	private $shop_id;
	private $since_id;
	private $option_name;
	private $fields = ['id', 'title', 'body_html', 'tags', 'updated_at', 'status', 'variants', 'image'];

	private TagsController $TagsController;
	private ProductsController $ProductsController;

	public function __construct(ProductsController $PC, TagsController $TC){
		$this->ProductsController = $PC;
		$this->TagsController = $TC;
	}

	public function syncShopsProducts(): array{
		$res = [];

		$shops = Settings::getLike('_sync_since_id');

		if(count($shops) == 0) return $res;

		foreach($shops as $name => $since_id){
			$this->shop_id = intval(explode('_', $name)[1]);
			$this->option_name = $name;
			$this->since_id = $since_id;

			#Log::stack(['cron'])->debug(['shop_id' => $this->shop_id, 'since_id' => $this->since_id]);
			
			$this->shopifyApi = new MyShopify($this->shop_id);
			
			$res[$this->shop_id] = $this->_updateOrCreateShopifyProducts();

			sleep(1);
		}

		return $res;
	}

	public function syncSalesChannel(): array{
		$res = [];

		$shops = Settings::getLike('_sales_channel_since_id');

		if(count($shops) == 0) return $res;

		foreach($shops as $name => $since_id){
			$this->shop_id = intval(explode('_', $name)[1]);
			$this->option_name = $name;
			$this->since_id = $since_id;

			#Log::stack(['cron'])->debug(['shop_id' => $this->shop_id, 'since_id' => $this->since_id]);

			$this->shopifyApi = new MyShopify($this->shop_id);

			$res[$this->shop_id] = $this->_updateOrCreateShopifyProducts();

			sleep(1);
		}

		return $res;
	}

	private function _updateOrCreateShopifyProducts(): int{
		$res = [];
		
		$shopify_products = $this->_getShopifyProducts();

		if(empty($shopify_products)) return 0;
		
		foreach($shopify_products as $s_product){
			$is_mystery_box = 0;
			$create_or_update_variants = false;
			$product_id = $s_product['id'];
			$variants = $s_product['variants'];
			$tags = $this->TagsController->parseProductTags($s_product['tags']);
			#$tags = $this->_parseProductTags($s_product['tags']);

			#if(empty($tags['link_depop']) && empty($tags['link_asos'])) continue;

			if(str_contains(strtolower($s_product['title']), 'mystery')){
				$is_mystery_box = 1;
			}

			$product = Product::where(['shop_id' => $this->shop_id, 'product_id' => $product_id])->get()->toArray();
			#Log::stack(['cron'])->debug($product);

			if(count($product) == 0){
				#Log::stack(['cron'])->debug('Create product ID '.$product_id);
				Product::create([
					'shop_id' => $this->shop_id,
					'product_id' => $product_id,
					'is_mystery' => $is_mystery_box,
					'title' => $s_product['title'],
					'body' => $s_product['body_html'],
					'status' => $s_product['status'],
					'p_updated_at' => $s_product['updated_at'],
					'link_depop' => $tags['link_depop'],
					'link_asos' => $tags['link_asos'],
					'image' => $s_product['image']['src'] ?: '',
				]);

				$this->TagsController->addTags($this->shop_id, $product_id, $tags['tags']);

				$create_or_update_variants = true;
				$res[] = $product_id;
			}else{
				$product = $product[0];
				if($s_product['updated_at'] !== $product['p_updated_at']){
					#Log::stack(['cron'])->debug('Update product ID '.$product['id']);

					Product::where(['shop_id' => $this->shop_id, 'product_id' => $product_id])
						->update([
							'is_mystery' => $is_mystery_box,
							'title' => $s_product['title'],
							'body' => $s_product['body_html'],
							'status' => $s_product['status'],
							'image' => $s_product['image']['src'] ?? '',
							'p_updated_at' => $s_product['updated_at'],
						]);

					$this->TagsController->renewTags($this->shop_id, $product_id, $tags['tags']);

					$create_or_update_variants = true;
					$res[] = $product_id;
				}
			}
			
			if($create_or_update_variants && count($variants)){
				foreach($variants as $variant){
					Variant::updateOrCreate(
						['shop_id' => $this->shop_id, 'product_id' => $product_id, 'variant_id' => $variant['id']],
						[
							'shop_id' => $this->shop_id,
							'product_id' => $variant['product_id'],
							'variant_id' => $variant['id'],
							'title' => $variant['title'],
							'option1' => $variant['option1'],
							'option2' => $variant['option2'],
							'option3' => $variant['option3'],
							'inventory_quantity' => $variant['inventory_quantity'],
							'price' => $variant['price'],
						]
					);
				}
			}
		}
		#Log::stack(['cron'])->debug($res);
		
		return count($res);
	}

	private function _getShopifyProducts(): array{
		$since_id = $this->since_id;
		
		$url = sprintf("/products.json?since_id=%d&limit=%d&fields=%s", $since_id, $this->limit_partials, implode(',', $this->fields));
		$result = $this->shopifyApi->get($url);
		
		$since_id = empty($result['products']) ? 0 : end($result['products'])['id'];

		if($since_id == 0){
			Settings::where(['name' => $this->option_name])->update([
				'value' => $since_id,
				'active' => 0
			]);
		}else{
			Settings::set($this->option_name, $since_id);
		}

		return $result['products'];
	}

	private function _updateProductsChannel(): int{
		$res = [];

		$shopify_products = $this->_getShopifyOnlineStoreProductIDs();
	}

	private function _getShopifyOnlineStoreProductIDs(): array{
		$since_id = $this->since_id;

		$url = sprintf("/products.json?since_id=%d&limit=%d&fields=%s", $since_id, $this->limit_partials, implode(',', $this->fields));
		$result = $this->shopifyApi->get($url);

		$since_id = empty($result['products']) ? 0 : end($result['products'])['id'];

		if($since_id == 0){
			Settings::where(['name' => $this->option_name])->update([
				'value' => $since_id,
				'active' => 0
			]);
		}else{
			Settings::set($this->option_name, $since_id);
		}

		return $result['products'];
	}

	/**------------------------------------------------------------**/

	private function _getShopifyAllProducts(): array{
		$shopify_products = [];
		
		$products_url = "/products.json?since_id={since_id}&limit={$this->limit_max}&fields=id";
		$products_count = $this->getShopifyProductsCount();
		$pages_count = intval(ceil($products_count / $this->limit_max));
		
		Log::stack(['cron'])->debug([$products_count, $pages_count]);
		
		$since_id = 0;
		for($i = 1; $i <= $pages_count; $i++){
			$url = str_replace('{since_id}', $since_id, $products_url);
			$result = $this->shopifyApi->get($url);
			foreach($result['products'] as $product){
				$shopify_products[] = $product['id'];
				$since_id = $product['id'];
			}
			sleep(1);
		}
		
		Log::stack(['cron'])->debug($shopify_products);
		
		return $shopify_products;
	}
	
	private function _getShopifyProductsCount(): int{
		$count_url = '/products/count.json';
		$result = $this->shopifyApi->get($count_url);
		
		return $result['count'];
	}


	/**
	 * ACTION for manual testing
	 *
	 * @return \Illuminate\Contracts\View\View
	 */
	public function shopifySync(){
		$sync_products = $this->syncShopsProducts();
		dd($sync_products);
		#return view('shopify.list', ['SyncProducts' => $sync_products]);
	}

}
