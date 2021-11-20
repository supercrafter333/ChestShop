<?php

namespace ChestShop;


use pocketmine\math\Vector3;

abstract class DataProvider
{
	/** @var ShopInfo[] $cache */
	private $cache = [];
	/** @var int $cacheSize */
	private $cacheSize;
	/** @var ChestShop $plugin */
	protected $plugin;

	/**
	 * DataProvider constructor.
	 *
	 * @param ChestShop $plugin
	 * @param int $cacheSize
	 */
	public function __construct(ChestShop $plugin, int $cacheSize = 0) {
		$this->plugin = $plugin;
		$this->cacheSize = $cacheSize;
	}

	protected final function cacheShopInfo(ShopInfo $shopInfo) : void {
		if($this->cacheSize > 0) {
			$key = $shopInfo->signX . ';' . $shopInfo->signY . ';' . $shopInfo->signZ;
			if(isset($this->cache[$key])) {
				unset($this->cache[$key]);
			}
			elseif($this->cacheSize <= count($this->cache)) {
				array_shift($this->cache);
			}
			$this->cache = array_merge([$key => clone $shopInfo], $this->cache);
			$this->plugin->getLogger()->debug("Shop {$shopInfo->signX};{$shopInfo->signY};{$shopInfo->signZ} has been cached");
		}
	}

	protected final function uncacheShopInfo(ShopInfo $shopInfo) : void {
		if($this->cacheSize > 0) {
			$key = $shopInfo->signX . ';' . $shopInfo->signY . ';' . $shopInfo->signZ;
			if(isset($this->cache[$key])) {
				unset($this->cache[$key]);
			}
		}
	}

	protected final function getShopInfoFromCache(int $X, int $Y, int $Z) : ?ShopInfo {
		if($this->cacheSize > 0) {
			$key = $X . ';' . $Y . ';' . $Z;
			if(isset($this->cache[$key])) {
				#$this->plugin->getLogger()->debug("Plot {$X};{$Z} was loaded from the cache");
				return $this->cache[$key];
			}
		}
		return null;
	}

	public abstract function saveShopInfo(ShopInfo $shopInfo) : bool;

	public abstract function deleteShopInfo(ShopInfo $shopInfo) : bool;

	public abstract function getShopInfoBySign(Vector3 $signPos) : ?ShopInfo;

	public abstract function getShopInfoByChest(Vector3 $chestPos) : ?ShopInfo;

	public abstract function getShopsByOwner(string $shopOwner) : array;

	public abstract function close() : void;
}