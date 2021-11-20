<?php
declare(strict_types=1);
namespace ChestShop;

use onebone\economyapi\EconomyAPI;
use pocketmine\block\BaseSign;
use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\tile\Chest;
use pocketmine\block\utils\SignText;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class EventListener implements Listener
{
	private ChestShop $plugin;
	private DatabaseManager $db;

	public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
	{
		$this->plugin = $plugin;
		$this->db = $databaseManager;
	}

	public function onPlayerInteract(PlayerInteractEvent $event) : void
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		if($block instanceof BaseSign) {
			if(($shopInfo = $this->db->getShopInfoBySign($block->getPosition())) === null)
				return;

			if ($shopInfo->shopOwner === $player->getName()) {
				$player->sendMessage("Cannot purchase from your own shop!");
				return;
			}

			$event->cancel();

			$buyerMoney = EconomyAPI::getInstance()->myMoney($player->getName());
			if ($buyerMoney === false) {
				$player->sendMessage("Couldn't acquire your money data!");
				return;
			}
			if ($buyerMoney < $shopInfo->price) {
				$player->sendMessage("Your money is not enough!");
				return;
			}

			/** @var Chest $chest */
			$chest = $player->getWorld()->getTile(new Vector3($shopInfo->chestX, $shopInfo->chestY, $shopInfo->chestZ));
			$itemNum = 0;

			$searchItem = StringToItemParser::getInstance()->parse($shopInfo->productName)->setCount($shopInfo->productAmount);
			$items = $chest->getInventory()->all($searchItem);
			foreach($items as $item) {
				$itemNum += $item->getCount();
			}
			if ($itemNum < $shopInfo->productAmount) {
				$player->sendMessage("This shop is out of stock!");
				if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo->shopOwner)) !== null) {
					$p->sendMessage("Your ChestShop is out of stock! Replenish Item: ".$searchItem->getName());
				}
				return;
			}

			$sellerMoney = EconomyAPI::getInstance()->myMoney($shopInfo->shopOwner);
			if(EconomyAPI::getInstance()->reduceMoney($player->getName(), $shopInfo->price, false, "ChestShop") === EconomyAPI::RET_SUCCESS and
				EconomyAPI::getInstance()->addMoney($shopInfo->shopOwner, $shopInfo->price, false, "ChestShop") === EconomyAPI::RET_SUCCESS) {
				$chest->getInventory()->removeItem($searchItem);
				$player->getInventory()->addItem($searchItem);
				$player->sendMessage("Completed transaction");
				if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo->shopOwner)) !== null) {
					$p->sendMessage("{$player->getName()} purchased ".$searchItem->getName()." for ".EconomyAPI::getInstance()->getMonetaryUnit().$shopInfo->price);
				}
			}else{
				EconomyAPI::getInstance()->setMoney($player->getName(), $buyerMoney);
				EconomyAPI::getInstance()->setMoney($shopInfo->shopOwner, $sellerMoney);
				$player->sendMessage("Transaction Failed");
			}
		}elseif($block instanceof \pocketmine\block\Chest) {
			$shopInfo = $this->db->getShopInfoByChest($block->getPosition());

			if ($shopInfo !== null and $shopInfo->shopOwner !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
				$player->sendMessage("This chest is protected! Please tap on the sign to trade.");
				$event->cancel();
			}
		}
	}

	public function onBlockBreak(BlockBreakEvent $event) : void
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		if($block instanceof BaseSign) {
			$shopInfo = $this->db->getShopInfoBySign($block->getPosition());

			if($shopInfo === null)
				return;

			if ($shopInfo->shopOwner !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
				$player->sendMessage("This sign is protected!");
				$event->cancel();
			} else {
				$this->db->deleteShopInfo($shopInfo);
				$player->sendMessage("Closed your ChestShop");
			}
		}elseif($block instanceof \pocketmine\block\Chest) {

			$shopInfo = $this->db->getShopInfoByChest($block->getPosition());

			if($shopInfo === null)
				return;

			if ($shopInfo->shopOwner !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
				$player->sendMessage("This chest is protected!");
				$event->cancel();
			} else {
				$this->db->deleteShopInfo($shopInfo);
				$player->sendMessage("Closed your ChestShop");
			}
		}
	}

	public function onSignChange(SignChangeEvent $event) : void
	{
		$signText = $event->getNewText();
		if ($signText->getLine(0) !== "") return;
		if($signText->getLine(3) === "") return; // only start doing work at last line of sign

		$productAmount = (int) $signText->getLine(1);
		$price = (int) $signText->getLine(2);
		$itemId = $signText->getLine(3);
		$this->isItem($itemId) ?: $itemId = null;

		if ($productAmount <= 0) return;
		if ($price < 0) return;
		if ($itemId === null) return;
		if (($chest = $this->getSideChest($sign = $event->getBlock()->getPosition())?->getPosition()->floor()) === null) return;
		$sign = $sign->floor();

		$shopOwner = $event->getPlayer()->getName();

		$shopDataByOwner = $this->db->getShopsByOwner($shopOwner);
		foreach($shopDataByOwner as $shopInfo) {
			if($shopInfo->signX === $sign->x AND $shopInfo->signY === $sign->y AND $shopInfo->signZ === $sign->z) { // do not check permissions if editing existing shop
				if(($productName = (StringToItemParser::getInstance()->parse($itemId))?->getName()) === null)
					throw new AssumptionFailedError("Item does not exist"); // it should exist at this stage
				$event->setNewText(new SignText([
					$shopOwner,
					"Amount: $productAmount",
					"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
					$productName
				]));
				$this->db->registerShop($shopOwner, $productAmount, $price, $itemId, $sign, $chest);
				return;
			}
		}

		if($this->plugin->getMaxPlayerShops($event->getPlayer())+1 <= count($shopDataByOwner) or $event->getPlayer()->hasPermission("chestshop.admin") and $event->getPlayer()->hasPermission("chestshop.makeshop.unlimited")) {
			$event->getPlayer()->sendMessage(TextFormat::RED."You don't have permission to make more shops");
			return;
		}

		$productName = StringToItemParser::getInstance()->parse($itemId)->getName();
		$event->setNewText(new SignText([
			$shopOwner,
			"Amount: $productAmount",
			"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
			$productName
		]));

		$this->db->registerShop($shopOwner, $productAmount, $price, $itemId, $sign, $chest);
	}

	private function getSideChest(Position $pos) : ?Block
	{
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		return null;
	}

	private function isItem(string &$id) : bool
	{
		return ($id = StringToItemParser::getInstance()->parse($id)?->getVanillaName()) !== null;
	}
} 
