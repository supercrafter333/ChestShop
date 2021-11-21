<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\item\ItemFactory;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;

class DatabaseManager extends DataProvider
{
	private $db;
	private \SQLite3Stmt $sqlSaveShopInfo;
	private \SQLite3Stmt $sqlRemoveShopInfo;
	private \SQLite3Stmt $sqlGetShopInfo;
	private \SQLite3Stmt $sqlGetShopsByOwner;

	public function __construct(ChestShop $plugin, int $cacheSize = 256)
	{
		parent::__construct($plugin, $cacheSize);
		$this->db = new \SQLite3($plugin->getDataFolder() . 'ChestShop.sqlite3');

		if(!$this->tryUpgradeDB()) {
			$this->db->exec("CREATE TABLE IF NOT EXISTS ChestShopV2(
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			shopOwner TEXT NOT NULL,
			productAmount INTEGER NOT NULL,
			price FLOAT NOT NULL,
			productName TEXT NOT NULL,
			signX INTEGER NOT NULL,
			signY INTEGER NOT NULL,
			signZ INTEGER NOT NULL,
			chestX INTEGER NOT NULL,
			chestY INTEGER NOT NULL,
			chestZ INTEGER NOT NULL
		);");
		}

		$this->sqlSaveShopInfo = $this->db->prepare("INSERT OR REPLACE INTO ChestShopV2 (id, shopOwner, productAmount, price, productName, signX, signY, signZ, chestX, chestY, chestZ) VALUES
			((SELECT id FROM ChestShopV2 WHERE signX = :signX AND signY = :signY AND signZ = :signZ),
			 :shopOwner, :productAmount, :price, :productName, :signX, :signY, :signZ, :chestX, :chestY, :chestZ);");
		$this->sqlRemoveShopInfo = $this->db->prepare("DELETE FROM ChestShopV2 WHERE signZ = :signZ AND signY = :signY AND signZ = :signZ;");
		$this->sqlGetShopInfo = $this->db->prepare("SELECT shopOwner, productAmount, price, productName, chestX, chestY, chestZ FROM ChestShopV2 WHERE signX = :signX AND signY = :signY AND signZ = :signZ;");
		$this->sqlGetShopsByOwner = $this->db->prepare("SELECT productAmount, price, productName, signX, signY, signZ, chestX, chestY, chestZ FROM ChestShopV2 WHERE shopOwner = :shopOwner;");
	}

	public function tryUpgradeDB() : bool
	{
		if($this->db->querySingle("SELECT count(name) FROM sqlite_Master WHERE type='table' AND name='ChestShop';") <= 0)
			return false; // we already upgraded

		$this->db->exec("CREATE TABLE IF NOT EXISTS ChestShopV2(
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			shopOwner TEXT NOT NULL,
			productAmount INTEGER NOT NULL,
			price FLOAT NOT NULL,
			productName TEXT NOT NULL,
			signX INTEGER NOT NULL,
			signY INTEGER NOT NULL,
			signZ INTEGER NOT NULL,
			chestX INTEGER NOT NULL,
			chestY INTEGER NOT NULL,
			chestZ INTEGER NOT NULL
		);");
		$query = $this->db->query("SELECT * FROM ChestShop;");
		while($val = $query->fetchArray(SQLITE3_ASSOC)) {
			if(($itemName = StringToItemParser::getInstance()->parse(
				ItemFactory::getInstance()->get((int)$val['productID'], (int)$val['productMeta'])->getVanillaName()
			)?->getVanillaName()) === null) {
				continue;
			}
			$this->registerShop($val['shopOwner'], $val['saleNum'], $val['price'], $itemName, new Vector3($val['signX'], $val['signY'], $val['signZ']), new Vector3($val['chestX'], $val['chestY'], $val['chestZ']));
		}
		return false;
	}

	public function registerShop(string $shopOwner, int $productAmount, float $price, string $productID, Vector3 $sign, Vector3 $chest) : bool
	{
		return $this->db->exec("INSERT OR REPLACE INTO ChestShopV2 (id, shopOwner, productAmount, price, productName, signX, signY, signZ, chestX, chestY, chestZ) VALUES
			((SELECT id FROM ChestShop WHERE signX = $sign->x AND signY = $sign->y AND signZ = $sign->z),
			'$shopOwner', $productAmount, $price, $productID, $sign->x, $sign->y, $sign->z, $chest->x, $chest->y, $chest->z)");
	}

	public function saveShopInfo(ShopInfo $shopInfo) : bool
	{
		$stmt = $this->sqlSaveShopInfo;
		$stmt->bindValue(':shopOwner', $shopInfo->shopOwner);
		$stmt->bindValue(':productAmount', $shopInfo->productAmount);
		$stmt->bindValue(':price', $shopInfo->price);
		$stmt->bindValue(':productName', $shopInfo->productName);
		$stmt->bindValue(':signX', $shopInfo->signX);
		$stmt->bindValue(':signY', $shopInfo->signY);
		$stmt->bindValue(':signZ', $shopInfo->signZ);
		$stmt->bindValue(':chestX', $shopInfo->chestX);
		$stmt->bindValue(':chestY', $shopInfo->chestY);
		$stmt->bindValue(':chestZ', $shopInfo->chestZ);
		$stmt->reset();
		$result = $stmt->execute();
		if(!$result instanceof \SQLite3Result) {
			return false;
		}
		$this->cacheShopInfo($shopInfo);
		return true;
	}

	public function deleteShopInfo(ShopInfo $shopInfo) : bool
	{
		$stmt = $this->sqlRemoveShopInfo;
		$stmt->bindValue(':signX', $shopInfo->signX);
		$stmt->bindValue(':signY', $shopInfo->signY);
		$stmt->bindValue(':signZ', $shopInfo->signZ);
		$stmt->reset();
		$result = $stmt->execute();
		if(!$result instanceof \SQLite3Result) {
			return false;
		}
		$this->uncacheShopInfo($shopInfo);
		return true;
	}

	public function getShopInfoBySign(Vector3 $signPos) : ?ShopInfo
	{
		$signPos = $signPos->floor();
		if(($shopInfo = $this->getShopInfoFromCache($signPos->x, $signPos->y, $signPos->z)) !== null) {
			return $shopInfo;
		}
		$stmt = $this->sqlGetShopInfo;
		$stmt->bindValue(':signX', $signPos->x);
		$stmt->bindValue(':signY', $signPos->y);
		$stmt->bindValue(':signZ', $signPos->z);
		$stmt->reset();
		$result = $stmt->execute();
		if($result !== false and ($val = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
			return new ShopInfo($val['shopOwner'], $val['productAmount'], $val['price'], $val['productName'], $signPos->x, $signPos->y, $signPos->z, $val['chestX'], $val['chestY'], $val['chestZ']);
		}
		return null;
	}

	public function getShopInfoByChest(Vector3 $chestPos) : ?ShopInfo
	{
		$chestPos = $chestPos->floor();
		$stmt = $this->sqlGetShopInfo;
		$stmt->bindValue(':chestX', $chestPos->x);
		$stmt->bindValue(':chestY', $chestPos->y);
		$stmt->bindValue(':chestZ', $chestPos->z);
		$stmt->reset();
		$result = $stmt->execute();
		if($result !== false and ($val = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
			return new ShopInfo($val['shopOwner'], $val['productAmount'], $val['price'], $val['productName'], $val['signX'], $val['signY'], $val['signZ'], $chestPos->x, $chestPos->y, $chestPos->z);
		}
		return null;
	}

	/**
	 * @param string $shopOwner
	 *
	 * @return ShopInfo[]
	 */
	public function getShopsByOwner(string $shopOwner) : array
	{
		$stmt = $this->sqlGetShopsByOwner;
		$stmt->bindValue(':shopOwner', $shopOwner);
		$stmt->reset();
		$result = $stmt->execute();

		$shops = [];
		while($result !== false and ($val = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
			$shops[] = new ShopInfo($shopOwner, $val['productAmount'], $val['price'], $val['productName'], $val['signX'], $val['signY'], $val['signZ'], $val['chestX'], $val['chestY'], $val['chestZ']);
		}
		return $shops;
	}

	public function close() : void
	{
		$this->db->close();
		$this->plugin->getLogger()->debug("SQLite database closed!");
	}
}