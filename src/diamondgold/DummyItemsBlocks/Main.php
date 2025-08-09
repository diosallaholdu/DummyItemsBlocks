<?php

namespace diamondgold\DummyItemsBlocks;

use Closure;
use diamondgold\DummyItemsBlocks\item\DummyItem;
use diamondgold\DummyItemsBlocks\item\firework\FireworkStar;
use diamondgold\DummyItemsBlocks\item\horn\GoatHorn;
use diamondgold\DummyItemsBlocks\item\horn\GoatHornType;
use diamondgold\DummyItemsBlocks\item\horn\GoatHornTypeIdMap;
use diamondgold\DummyItemsBlocks\item\ItemPlacedAsBlock;
use diamondgold\DummyItemsBlocks\tile\DummyTile;
use diamondgold\DummyItemsBlocks\tile\TileNames;
use diamondgold\DummyItemsBlocks\util\BlockStateRegistration;
use diamondgold\DummyItemsBlocks\util\DummyBlocks;
use diamondgold\DummyItemsBlocks\util\DummyItems;
use diamondgold\DummyItemsBlocks\util\ReflectionHelper;
use diamondgold\DummyItemsBlocks\util\Utils;
use InvalidArgumentException;
use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\Opaque;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\WoodType;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\crafting\json\ItemStackData;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\block\convert\UnsupportedBlockStateException;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\data\bedrock\item\ItemSerializerDeserializerRegistrar;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemBlockWallOrFloor;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotionType;
use pocketmine\item\SplashPotion;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\player\Player;
use pocketmine\plugin\DisablePluginException;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\ConfigLoadException;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Throwable;

final class Main extends PluginBase
{
    /** @var Closure(Block, ?Player): bool */
    private static Closure $canChangeBlockStatesClosure;

    protected function onLoad(): void
    {
        self::setCanChangeStatesClosure(function (Block $block, ?Player $player): bool {
            if ($player === null) {
                return false;
            }
            static $antiSpam = [];
            if (($antiSpam[$player->getId()] ?? 0) > microtime(true)) {
                return false;
            }
            $antiSpam[$player->getId()] = microtime(true) + 0.2;
            static $item = null;
            if ($item === null) {
                $item = VanillaItems::ARROW()->setCustomName("Change State"); // give player arrow 1 {display:{Name:"Change State"}}
            }
            return
                $player->isCreative(true) &&
                $player->hasPermission("dummyitemsblocks.changestate") &&
                ($player->getInventory()->getItemInHand()->equals($item) || $player->getOffHandInventory()->getItem(0)->equals($item));
        });
    }

    /**
     * @param Closure(Block, ?Player): bool $closure
     */
    public static function setCanChangeStatesClosure(Closure $closure): void
    {
        \pocketmine\utils\Utils::validateCallableSignature(fn(Block $block, ?Player $player): bool => true, $closure);
        self::$canChangeBlockStatesClosure = $closure;
    }

    public static function canChangeBlockStates(Block $block, ?Player $player): bool
    {
        return (self::$canChangeBlockStatesClosure)($block, $player);
    }

    protected function onEnable(): void
    {
        $this->saveDefaultConfig();
        $config = $this->getConfig();
        //$config->set("blocks", ReflectionHelper::BlockTypeNames());
        $removedBlocks = [
            BlockTypeNames::CLIENT_REQUEST_PLACEHOLDER_BLOCK,
            //BlockTypeNames::MOVING_BLOCK, // should I remove or not? Does not seem to be useful since it is not interact-able and invisible
        ];
        $blocks = $config->get("blocks", []);
        if (!is_array($blocks)) {
            throw new ConfigLoadException("Config 'blocks' must be string array");
        }
        foreach ($blocks as $k => $id) {
            if (!is_string($id)) {
                throw new ConfigLoadException("Config 'blocks' at index $k must be a string, got " . gettype($id));
            }
            if (in_array($id, $removedBlocks, true)) {
                $this->getLogger()->warning("Block $id is intentionally removed!");
                unset($blocks[$k]);
            }
            try {
                GlobalBlockStateHandlers::getDeserializer()->deserializeBlock(BlockStateData::current($id, []));
                $this->getLogger()->warning("Block $id is already registered!");
                unset($blocks[$k]);
            } catch (UnsupportedBlockStateException) {
                // not registered
            } catch (BlockStateDeserializeException) {
                // registered but missing properties when deserializing
                $this->getLogger()->warning("Block $id is already registered!");
                unset($blocks[$k]);
            }
        }
        //$config->set("items", ReflectionHelper::ItemTypeNames());
        $items = $config->get("items", []);
        if (!is_array($items)) {
            throw new ConfigLoadException("Config 'items' must be string array");
        }
        $removedItems = [
            ItemTypeNames::SPAWN_EGG,
            ItemTypeNames::CHEST_BOAT,
            ItemTypeNames::BANNER_PATTERN,
        ];
        foreach ($items as $k => $id) {
            if (in_array($id, $removedItems, true)) {
                $this->getLogger()->warning("Item $id is intentionally removed!");
                unset($items[$k]);
            }
            try {
                if (StringToItemParser::getInstance()->parse($id)) {
                    $this->getLogger()->warning("Item $id is in StringToItemParser!");
                    unset($items[$k]);
                    continue;
                }
                GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($id));
                $this->getLogger()->warning("Item $id is already registered!");
                unset($items[$k]);
            } catch (Throwable) {
            }
        }

        foreach ([
                     "VanillaElytra" => [ItemTypeNames::ELYTRA],
                     "Composter" => [BlockTypeNames::COMPOSTER],
                     "Crossbow" => [ItemTypeNames::CROSSBOW],
                     "Trident" => [ItemTypeNames::TRIDENT], // PM-Trident
                     "Shield" => [ItemTypeNames::SHIELD],
                     "RecoveryCompass" => [ItemTypeNames::RECOVERY_COMPASS],
                     "Fireworks" => [ItemTypeNames::FIREWORK_ROCKET],
                 ] as $pluginName => $pluginItemsBlocks) {
            if ($this->getServer()->getPluginManager()->getPlugin($pluginName)) {
                foreach ($pluginItemsBlocks as $removeId) {
                    if (Utils::removeIfPresent($removeId, $blocks) || Utils::removeIfPresent($removeId, $items)
                    ) {
                        $this->getLogger()->notice("Detected plugin $pluginName, removed $removeId");
                    }
                }
            }
        }
        // Sign: Item must be registered if block is registered, if not remove block
        $itemName = ItemTypeNames::BAMBOO_SIGN;
        $blockNames = [BlockTypeNames::BAMBOO_STANDING_SIGN, BlockTypeNames::BAMBOO_WALL_SIGN];
        $blockFound = false;
        foreach ($blockNames as $blockName) {
            if (in_array($blockName, $blocks)) {
                $blockFound = true;
                break;
            }
        }
        if ($blockFound) {
            if (!in_array($itemName, $items, true)) {
                foreach ($blockNames as $blockName) {
                    if (Utils::removeIfPresent($blockName, $blocks)) {
                        $this->getLogger()->warning("Removed Block $blockName as Item $itemName is not registered");
                    }
                }
            }
        }

        $blocks = array_values($blocks);
        //sort($blocks);
        $items = array_values($items);
        //sort($items);
        if ($config->get("blocks") !== $blocks) {
            $config->set("blocks", $blocks);
        }
        if ($config->get("items") !== $items) {
            $config->set("items", $items);
        }
        if ($config->hasChanged()) {
            $config->save();
        }

        $this->getLogger()->debug("Registering " . count($blocks) . " blocks and " . count($items) . " items");

        $blocksWithoutSpecial = $blocks;
        self::registerSpecialBlocks($blocksWithoutSpecial);
        self::registerBlocks($blocksWithoutSpecial);

        $itemsWithoutSpecial = $items;
        self::registerItemsPlacedAsBlock($itemsWithoutSpecial, $blocks);
        self::registerSpecialItems($itemsWithoutSpecial);
        self::registerItems($itemsWithoutSpecial);

        $this->registerDummyTiles($blocks);

        // Server will crash if it tries to send these items to the client
        // These ItemBlocks require block state data when registering
        $changed = false;
        /** @var string $alias */
        foreach (StringToItemParser::getInstance()->getKnownAliases() as $alias) {
            $alias = "minecraft:$alias";
            $item = StringToItemParser::getInstance()->parse($alias);
            assert($item instanceof Item);
            try {
                TypeConverter::getInstance()->coreItemStackToNet($item);
            } catch (AssumptionFailedError) {
                // Unmapped blockstate returned by blockstate serializer
                if ($item instanceof ItemBlock) {
                    $this->getLogger()->warning("Block $alias is not supported");
                    Utils::removeIfPresent($alias, $blocks);
                    Utils::removeIfPresent(str_replace('_block', '', $alias), $blocks);
                    //file_put_contents("unsupportedBlock.txt", "- $alias\n", FILE_APPEND);
                    /*
                    $this->getLogger()->debug("Expected:");
                    foreach (TypeConverter::getInstance()->getBlockTranslator()->getBlockStateDictionary()->getStates() as $state) {
                        if ($state->getStateName() === $alias || $state->getStateName() === str_replace('_block', '', $alias)) {
                            foreach (BlockStateDictionaryEntry::decodeStateProperties($state->getRawStateProperties()) as $name => $tag) {
                                $this->getLogger()->debug("$name " . $tag->toString());
                            }
                            break;
                        }
                    }
                    $this->getLogger()->debug("Got:");
                    foreach (GlobalBlockStateHandlers::getSerializer()->serializeBlock($item->getBlock())->getStates() as $name => $tag) {
                        $this->getLogger()->debug("$name " . $tag->toString());
                    }
                    */
                } else {
                    $this->getLogger()->warning("Item $alias is not supported");
                    Utils::removeIfPresent($alias, $items);
                    //file_put_contents("unsupportedItem.txt", "- $alias\n", FILE_APPEND);
                }
                $changed = true;
            } catch (Throwable $e) {
                if ($item instanceof ItemBlock) {
                    $this->getLogger()->warning("Block $alias is not supported: " . $e->getMessage());
                    Utils::removeIfPresent($alias, $blocks);
                    Utils::removeIfPresent(str_replace('_block', '', $alias), $blocks);
                    //file_put_contents("unsupportedBlock.txt", "- $alias\n", FILE_APPEND);
                } else {
                    $this->getLogger()->warning("Item $alias is not supported: " . $e->getMessage());
                    Utils::removeIfPresent($alias, $items);
                    //file_put_contents("unsupportedItem.txt", "- $alias\n", FILE_APPEND);
                }
                $changed = true;
            }
        }
        if ($changed) {
            $blocks = array_values($blocks);
            $items = array_values($items);
            $config->set("items", $items);
            $config->set("blocks", $blocks);
            $config->save();
            $this->getLogger()->emergency("Server restart required to remove unsupported items");
            throw new DisablePluginException();
        }
        /*
        // reload creative inventory from json file
        // pro: no need to add items manually
        // con: likely incompatible with other plugins that add to creative inventory

        CreativeInventory::getInstance()->clear();
        $creativeItems = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(
            BedrockDataFiles::CREATIVEITEMS_JSON,
            ItemStackData::class
        );
        foreach ($creativeItems as $data) {
            $item = CraftingManagerFromDataHelper::deserializeItemStack($data);
            if ($item === null) {
                $this->getLogger()->debug("Creative item $data->name");
                continue;
            }
            CreativeInventory::getInstance()->add($item);
        }
        */
        $blocksSerialized = igbinary_serialize($blocks);
        $itemsSerialized = igbinary_serialize($items);
        assert($blocksSerialized !== null);
        assert($itemsSerialized !== null);
        $this->getServer()->getAsyncPool()->addWorkerStartHook(function (int $worker) use ($blocksSerialized, $itemsSerialized): void {
            $this->getServer()->getAsyncPool()->submitTaskToWorker(new class($blocksSerialized, $itemsSerialized) extends AsyncTask {

                public function __construct(protected string $blocksSerialized, protected string $itemsSerialized)
                {

                }

                public function onRun(): void
                {
                    /** @var string[] $items */
                    $items = igbinary_unserialize($this->itemsSerialized);
                    /** @var string[] $blocks */
                    $blocks = igbinary_unserialize($this->blocksSerialized);
                    $blocksWithoutSpecial = $blocks;
                    Main::registerSpecialBlocks($blocksWithoutSpecial);
                    Main::registerBlocks($blocksWithoutSpecial);
                    Main::registerItemsPlacedAsBlock($items, $blocks);
                    Main::registerSpecialItems($items);
                    Main::registerItems($items);
                }
            }, $worker);
        });
    }

    /**
     * @param string[] $blocks
     * @return void
     * @internal
     */
    public static function registerBlocks(array $blocks): void
    {
        // Blocks with same id as item, add _block suffix for StringToItemParser
        $_block = [];
        $blockNames = ReflectionHelper::BlockTypeNames();
        foreach (ReflectionHelper::ItemTypeNames() as $item) {
            if (in_array($item, $blockNames)) {
                $_block[$item] = $item . '_block';
            }
        }
        foreach ($blocks as $id) {
            self::registerSimpleBlock(
                $id,
                new Opaque(new BlockIdentifier(BlockTypeIds::newId()), Utils::generateNameFromId($id), new BlockTypeInfo(BlockBreakInfo::instant())),
                [$_block[$id] ?? $id]
            );
        }
    }

    /**
     * @param string[] $blocks
     * @return void
     * @internal
     */
    public static function registerSpecialBlocks(array &$blocks): void
    {
        foreach ([
                     BlockTypeNames::PISTON_ARM_COLLISION,
                     BlockTypeNames::STICKY_PISTON_ARM_COLLISION,
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::anyFacingTransparent($id);
            }
        }
        // MULTI_FACE_DIRECTION_BITS 0-63
        $id = BlockTypeNames::SCULK_VEIN;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::multiFaceDirection($id);
        }
        // PILLAR_AXIS
        foreach ([
                     BlockTypeNames::BAMBOO_BLOCK,
                     BlockTypeNames::STRIPPED_BAMBOO_BLOCK,
                     BlockTypeNames::INFESTED_DEEPSLATE,
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::pillar($id);
            }
        }
        // hanging signs ATTACHED_BIT FACING_DIRECTION GROUND_SIGN_DIRECTION HANGING T/F
        foreach ([
                     BlockTypeNames::ACACIA_HANGING_SIGN,
                     BlockTypeNames::BAMBOO_HANGING_SIGN,
                     BlockTypeNames::BIRCH_HANGING_SIGN,
                     BlockTypeNames::CHERRY_HANGING_SIGN,
                     BlockTypeNames::CRIMSON_HANGING_SIGN,
                     BlockTypeNames::DARK_OAK_HANGING_SIGN,
                     BlockTypeNames::JUNGLE_HANGING_SIGN,
                     BlockTypeNames::MANGROVE_HANGING_SIGN,
                     BlockTypeNames::OAK_HANGING_SIGN,
                     BlockTypeNames::SPRUCE_HANGING_SIGN,
                     BlockTypeNames::WARPED_HANGING_SIGN,
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::HangingSign($id);
            }
        }
        // bee_hive DIRECTION HONEY_LEVEL 0-5
        foreach ([BlockTypeNames::BEEHIVE, BlockTypeNames::BEE_NEST] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::BeeHive($id);
            }
        }
        $id = BlockTypeNames::BORDER_BLOCK;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::wall($id);
        }
        // bubble_column DRAG_DOWN T/F
        if (Utils::removeIfPresent(BlockTypeNames::BUBBLE_COLUMN, $blocks)) {
            BlockStateRegistration::BubbleColumn();
        }
        // calibrated_sculk_sensor DIRECTION sculk_sensor_phase 0-1
        if (Utils::removeIfPresent(BlockTypeNames::CALIBRATED_SCULK_SENSOR, $blocks)) {
            BlockStateRegistration::CalibratedSculkSensor();
        }
        // campfire soul_campfire EXTINGUISHED T/F DIRECTION
        foreach ([BlockTypeNames::CAMPFIRE, BlockTypeNames::SOUL_CAMPFIRE] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::Campfire($id);
            }
        }
        $id = BlockTypeNames::BAMBOO_BUTTON;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::button($id); // registered as stone button so that WoodType is not required
        }
        $id = BlockTypeNames::BAMBOO_DOOR;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::door($id);
        }
        $id = BlockTypeNames::BAMBOO_FENCE_GATE;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::fenceGate($id, WoodType::OAK()); // will probably be obsolete when WoodType exists, it doesn't matter now since it's only for runtime
        }
        // can't register separately, either both or none
        foreach ([
                     BlockTypeNames::BAMBOO_SLAB => BlockTypeNames::BAMBOO_DOUBLE_SLAB,
                     BlockTypeNames::BAMBOO_MOSAIC_SLAB => BlockTypeNames::BAMBOO_MOSAIC_DOUBLE_SLAB,
                 ] as $singleId => $doubleId) {
            if (Utils::removeIfPresent($singleId, $blocks) && Utils::removeIfPresent($doubleId, $blocks)) {
                BlockStateRegistration::slab($singleId, $doubleId);
            }
        }
        // can't register separately, either both or none
        $standingId = BlockTypeNames::BAMBOO_STANDING_SIGN;
        $wallId = BlockTypeNames::BAMBOO_WALL_SIGN;
        if (Utils::removeIfPresent($standingId, $blocks) && Utils::removeIfPresent($wallId, $blocks)) {
            BlockStateRegistration::sign($standingId, $wallId, DummyBlocks::BAMBOO_STANDING_SIGN(), DummyBlocks::BAMBOO_WALL_SIGN());
        }
        foreach ([BlockTypeNames::BAMBOO_STAIRS, BlockTypeNames::BAMBOO_MOSAIC_STAIRS] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::stairs($id);
            }
        }
        $id = BlockTypeNames::BAMBOO_TRAPDOOR;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::trapdoor($id);
        }
        $id = BlockTypeNames::BAMBOO_PRESSURE_PLATE;
        if (Utils::removeIfPresent($id, $blocks)) {
            BlockStateRegistration::simplePressurePlate($id); // registered as stone pressure plate so that WoodType is not required
        }

        // cherry_sapling AGE_BIT T/F CANNOT use encodeSapling() no SAPLING_TYPE
        if (Utils::removeIfPresent(BlockTypeNames::CHERRY_SAPLING, $blocks)) {
            BlockStateRegistration::CherrySapling();
        }
        // chain_command_block command_block repeating_command_block CONDITIONAL_BIT T/F FACING_DIRECTION
        foreach ([BlockTypeNames::COMMAND_BLOCK, BlockTypeNames::REPEATING_COMMAND_BLOCK, BlockTypeNames::CHAIN_COMMAND_BLOCK] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::CommandBlock($id);
            }
        }
        // composter COMPOSTER_FILL_LEVEL 0-8
        if (Utils::removeIfPresent(BlockTypeNames::COMPOSTER, $blocks)) {
            BlockStateRegistration::Composter();
        }
        // decorated_pot DIRECTION
        if (Utils::removeIfPresent(BlockTypeNames::DECORATED_POT, $blocks)) {
            BlockStateRegistration::DecoratedPot();
        }
        // dispenser dropper FACING_DIRECTION TRIGGERED_BIT 0-1
        foreach ([BlockTypeNames::DISPENSER, BlockTypeNames::DROPPER] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::Dispenser($id);
            }
        }
        // grindstone DIRECTION ATTACHMENT 0-3
        if (Utils::removeIfPresent(BlockTypeNames::GRINDSTONE, $blocks)) {
            BlockStateRegistration::Grindstone();
        }
        // jigsaw ROTATION 0-3 FACING_DIRECTION
        if (Utils::removeIfPresent(BlockTypeNames::JIGSAW, $blocks)) {
            BlockStateRegistration::Jigsaw();
        }
        // kelp KELP_AGE
        if (Utils::removeIfPresent(BlockTypeNames::KELP, $blocks)) {
            BlockStateRegistration::Kelp();
        }
        // mangrove_propagule HANGING T/F PROPAGULE_STAGE 0
        if (Utils::removeIfPresent(BlockTypeNames::MANGROVE_PROPAGULE, $blocks)) {
            BlockStateRegistration::MangrovePropagule();
        }
        // observer FACING_DIRECTION POWERED_BIT T/F
        if (Utils::removeIfPresent(BlockTypeNames::OBSERVER, $blocks)) {
            BlockStateRegistration::Observer();
        }
        // piston sticky_piston FACING_DIRECTION
        foreach ([BlockTypeNames::PISTON, BlockTypeNames::STICKY_PISTON] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::Piston($id);
            }
        }
        // pointed_dripstone DRIPSTONE_THICKNESS string HANGING T/F
        if (Utils::removeIfPresent(BlockTypeNames::POINTED_DRIPSTONE, $blocks)) {
            BlockStateRegistration::PointedDripstone();
        }
        // respawn_anchor RESPAWN_ANCHOR_CHARGE 0-4
        if (Utils::removeIfPresent(BlockTypeNames::RESPAWN_ANCHOR, $blocks)) {
            BlockStateRegistration::RespawnAnchor();
        }
        // scaffolding STABILITY 0-7 STABILITY_CHECK T/F
        if (Utils::removeIfPresent(BlockTypeNames::SCAFFOLDING, $blocks)) {
            BlockStateRegistration::Scaffolding();
        }
        // sculk_catalyst BLOOM T/F
        if (Utils::removeIfPresent(BlockTypeNames::SCULK_CATALYST, $blocks)) {
            BlockStateRegistration::SculkCatalyst();
        }
        // sculk_sensor POWERED_BIT T/F sculk_sensor_phase 0-1
        if (Utils::removeIfPresent(BlockTypeNames::SCULK_SENSOR, $blocks)) {
            BlockStateRegistration::SculkSensor();
        }
        // sculk_shrieker ACTIVE 0-1 CAN_SUMMON T/F
        if (Utils::removeIfPresent(BlockTypeNames::SCULK_SHRIEKER, $blocks)) {
            BlockStateRegistration::SculkShrieker();
        }
        // seagrass SEA_GRASS_TYPE string
        if (Utils::removeIfPresent(BlockTypeNames::SEAGRASS, $blocks)) {
            BlockStateRegistration::SeaGrass();
        }
        // sniffer_egg CRACKED_STATE string
        if (Utils::removeIfPresent(BlockTypeNames::SNIFFER_EGG, $blocks)) {
            BlockStateRegistration::SnifferEgg();
        }
        // structure_block STRUCTURE_BLOCK_TYPE string
        if (Utils::removeIfPresent(BlockTypeNames::STRUCTURE_BLOCK, $blocks)) {
            BlockStateRegistration::StructureBlock();
        }
        // structure_void STRUCTURE_VOID_TYPE string
        if (Utils::removeIfPresent(BlockTypeNames::STRUCTURE_VOID, $blocks)) {
            BlockStateRegistration::StructureVoid();
        }
        // suspicious_gravel suspicious_sand BRUSHED_PROGRESS 0 HANGING T/F
        foreach ([BlockTypeNames::SUSPICIOUS_GRAVEL, BlockTypeNames::SUSPICIOUS_SAND] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::SuspiciousFallable($id);
            }
        }
        // turtle_egg TURTLE_EGG_COUNT string CRACKED_STATE string
        if (Utils::removeIfPresent(BlockTypeNames::TURTLE_EGG, $blocks)) {
            BlockStateRegistration::TurtleEgg();
        }
        // copper bulb LIT POWERED_BIT
        foreach ([
                     BlockTypeNames::COPPER_BULB,
                     BlockTypeNames::EXPOSED_COPPER_BULB,
                     BlockTypeNames::OXIDIZED_COPPER_BULB,
                     BlockTypeNames::WEATHERED_COPPER_BULB,
                     BlockTypeNames::WAXED_COPPER_BULB,
                     BlockTypeNames::WAXED_EXPOSED_COPPER_BULB,
                     BlockTypeNames::WAXED_OXIDIZED_COPPER_BULB,
                     BlockTypeNames::WAXED_WEATHERED_COPPER_BULB
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::CopperBulb($id);
            }
        }
        foreach ([
                     BlockTypeNames::COPPER_DOOR,
                     BlockTypeNames::EXPOSED_COPPER_DOOR,
                     BlockTypeNames::OXIDIZED_COPPER_DOOR,
                     BlockTypeNames::WEATHERED_COPPER_DOOR,
                     BlockTypeNames::WAXED_COPPER_DOOR,
                     BlockTypeNames::WAXED_EXPOSED_COPPER_DOOR,
                     BlockTypeNames::WAXED_OXIDIZED_COPPER_DOOR,
                     BlockTypeNames::WAXED_WEATHERED_COPPER_DOOR
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::door($id);
            }
        }
        foreach ([
                     BlockTypeNames::COPPER_TRAPDOOR,
                     BlockTypeNames::EXPOSED_COPPER_TRAPDOOR,
                     BlockTypeNames::OXIDIZED_COPPER_TRAPDOOR,
                     BlockTypeNames::WEATHERED_COPPER_TRAPDOOR,
                     BlockTypeNames::WAXED_COPPER_TRAPDOOR,
                     BlockTypeNames::WAXED_EXPOSED_COPPER_TRAPDOOR,
                     BlockTypeNames::WAXED_OXIDIZED_COPPER_TRAPDOOR,
                     BlockTypeNames::WAXED_WEATHERED_COPPER_TRAPDOOR
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::trapdoor($id);
            }
        }
        // crafter CRAFTING TRIGGERED_BIT ORIENTATION
        if (Utils::removeIfPresent(BlockTypeNames::CRAFTER, $blocks)) {
            BlockStateRegistration::Crafter();
        }
        // can't register separately, either both or none
        foreach ([
                     BlockTypeNames::TUFF_SLAB => BlockTypeNames::TUFF_DOUBLE_SLAB,
                     BlockTypeNames::TUFF_BRICK_SLAB => BlockTypeNames::TUFF_BRICK_DOUBLE_SLAB,
                     BlockTypeNames::POLISHED_TUFF_SLAB => BlockTypeNames::POLISHED_TUFF_DOUBLE_SLAB,
                 ] as $singleId => $doubleId) {
            if (Utils::removeIfPresent($singleId, $blocks) && Utils::removeIfPresent($doubleId, $blocks)) {
                BlockStateRegistration::slab($singleId, $doubleId);
            }
        }
        foreach ([
                     BlockTypeNames::TUFF_STAIRS,
                     BlockTypeNames::TUFF_BRICK_STAIRS,
                     BlockTypeNames::POLISHED_TUFF_STAIRS
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::stairs($id);
            }
        }
        foreach ([
                     BlockTypeNames::TUFF_WALL,
                     BlockTypeNames::TUFF_BRICK_WALL,
                     BlockTypeNames::POLISHED_TUFF_WALL
                 ] as $id) {
            if (Utils::removeIfPresent($id, $blocks)) {
                BlockStateRegistration::wall($id);
            }
        }
        // trial spawner trial_spawner_state int 1
        if (Utils::removeIfPresent(BlockTypeNames::TRIAL_SPAWNER, $blocks)) {
            BlockStateRegistration::TrialSpawner();
        }
        // vault cardinal_direction vault_state
        if (Utils::removeIfPresent(BlockTypeNames::VAULT, $blocks)) {
            BlockStateRegistration::Vault();
        }
    }

    /**
     * @param string[] $items
     * @return void
     * @internal
     */
    public static function registerItems(array $items): void
    {
        foreach ($items as $id) {
            self::registerSimpleItem($id, new DummyItem(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id)), [$id], $id !== ItemTypeNames::FILLED_MAP);
        }
    }

    /**
     * @param string[] $items
     * @param string[] $blocks
     * @return void
     * @internal
     */
    public static function registerItemsPlacedAsBlock(array &$items, array $blocks): void
    {
        // Examples such as Campfire & Hanging Signs, with the assumption that the item have the same ID as block
        $list = [];
        $blockNames = ReflectionHelper::BlockTypeNames();
        foreach (ReflectionHelper::ItemTypeNames() as $item) {
            if (in_array($item, $blockNames)) {
                $list[] = $item;
            }
        }
        foreach ($list as $id) {
            if (in_array($id, $items, true) && in_array($id, $blocks, true)) { // should not remove if either one is not present (register as normal item)
                Utils::removeIfPresent($id, $items);
                $block = StringToItemParser::getInstance()->parse($id . '_block')?->getBlock();
                if ($block === null) {
                    throw new AssumptionFailedError("Block {$id}_block not registered in StringToItemParser");
                }
                self::registerSimpleItem($id, new ItemPlacedAsBlock(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id), $block), [$id]);
            }
        }
        // special case
        $registerSign = function (string $itemId, array $blockIds, ItemBlockWallOrFloor $item) use (&$items, $blocks): void {
            if (in_array($itemId, $items, true)) {
                $register = true;
                foreach ($blockIds as $b) {
                    if (!in_array($b, $blocks, true)) {
                        $register = false;
                        break;
                    }
                }
                if ($register) { // should not remove if either one is not present (register as normal item)
                    Utils::removeIfPresent($itemId, $items);
                    self::registerSimpleItem($itemId, $item, [$itemId]);
                }
            }
        };
        $registerSign(
            ItemTypeNames::BAMBOO_SIGN,
            [BlockTypeNames::BAMBOO_WALL_SIGN, BlockTypeNames::BAMBOO_STANDING_SIGN],
            DummyItems::BAMBOO_SIGN()
        );

        // obsolete when merged https://github.com/pmmp/PocketMine-MP/pull/5964
        $blockId = BlockTypeNames::POWDER_SNOW;
        $itemId = ItemTypeNames::POWDER_SNOW_BUCKET;
        if (in_array($itemId, $items, true) && in_array($blockId, $blocks, true)) { //should not remove if either one is not present (register as normal item)
            Utils::removeIfPresent($itemId, $items);
            $block = StringToItemParser::getInstance()->parse($blockId)?->getBlock();
            if ($block === null) {
                throw new AssumptionFailedError("Block $blockId not registered in StringToItemParser");
            }
            self::registerSimpleItem($itemId, new ItemPlacedAsBlock(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($itemId), $block), [$itemId]);
        }
    }

    /**
     * @param string[] $items
     * @return void
     * @internal
     */
    public static function registerSpecialItems(array &$items): void
    {
        // obsolete when merged https://github.com/pmmp/PocketMine-MP/pull/5276
        $id = ItemTypeNames::LINGERING_POTION;
        if (Utils::removeIfPresent($id, $items)) {
            $item = new SplashPotion(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id));
            self::map1to1ItemWithMeta(
                $id,
                $item,
                function (SplashPotion $item, int $meta): void {
                    $item->setType(PotionTypeIdMap::getInstance()->fromId($meta) ?? throw new ItemTypeDeserializeException("Unknown potion type ID $meta"));
                },
                fn(SplashPotion $item) => PotionTypeIdMap::getInstance()->toId($item->getType())
            );
            StringToItemParser::getInstance()->register($id, fn() => clone $item);
            // For some reason it disappears from client-side creative inventory if I do registerBlocks() first... why Mojang...?
            foreach (PotionType::cases() as $type) {
                $potion = (clone $item)->setType($type);
                CreativeInventory::getInstance()->add($potion);
                $name = explode(':', $id);
                StringToItemParser::getInstance()->register($name[0] . ':' . $type->name . '_' . $name[1], fn() => clone $potion);
            }
        }
        // bare minimum code needed for non-functional item adapted from https://github.com/pmmp/PocketMine-MP/pull/5232
        // obsolete when merged
        $id = ItemTypeNames::GOAT_HORN;
        if (Utils::removeIfPresent($id, $items)) {
            $item = new GoatHorn(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id));
            self::map1to1ItemWithMeta(
                $id,
                $item,
                function (GoatHorn $item, int $meta): void {
                    $item->setType(GoatHornTypeIdMap::getInstance()->fromId($meta) ?? throw new ItemTypeDeserializeException("Unknown horn type ID $meta"));
                },
                fn(GoatHorn $item) => GoatHornTypeIdMap::getInstance()->toId($item->getType())
            );
            StringToItemParser::getInstance()->register($id, fn() => clone $item);
            foreach (GoatHornType::cases() as $type) {
                $horn = (clone $item)->setType($type);
                CreativeInventory::getInstance()->add($horn);
                $name = explode(':', $id);
                StringToItemParser::getInstance()->register($name[0] . ':' . $type->name . '_' . $name[1], fn() => clone $horn);
            }
        }
        // im too lazy to list all the items with compound tag data, easier to just reload ;P
                $creativeDir = BedrockDataFiles::CREATIVE;
        $creativeItems = [];

        foreach (scandir($creativeDir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                $path = $creativeDir . '/' . $file;
                $jsonData = json_decode(file_get_contents($path), true);
        
                if (isset($jsonData["items"])) {
                    foreach ($jsonData["items"] as $item) {
                        if (isset($item["id"])) {
                            $creativeItems[] = $item;
                        }
                    }
                }
            }
        }
        // bare minimum code needed for non-functional item adapted from https://github.com/pmmp/PocketMine-MP/pull/5455
        // obsolete when merged
        $id = ItemTypeNames::FIREWORK_STAR;
        if (Utils::removeIfPresent($id, $items)) {
            $item = new FireworkStar(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id));
            self::map1to1ItemWithMeta(
                $id,
                $item,
                function (FireworkStar $item, int $meta): void {
                    // Colors will be defined by CompoundTag deserialization.
                },
                fn(FireworkStar $item) => DyeColorIdMap::getInstance()->toInvertedId($item->getExplosion()->getFlashColor())
            );
            StringToItemParser::getInstance()->register($id, fn() => clone $item);
            foreach ($creativeItems as $data) {
                if ($data->name === $id) {
                    $item = CraftingManagerFromDataHelper::deserializeItemStack($data);
                    if ($item) {
                        CreativeInventory::getInstance()->add($item);
                    }
                }
            }
        }
        $id = ItemTypeNames::FIREWORK_ROCKET;
        if (Utils::removeIfPresent($id, $items)) {
            $item = new DummyItem(new ItemIdentifier(ItemTypeIds::newId()), Utils::generateNameFromId($id));
            self::registerSimpleItem($id, $item, [$id], false);
            foreach ($creativeItems as $data) {
                if ($data->name === $id) {
                    $item = CraftingManagerFromDataHelper::deserializeItemStack($data);
                    if ($item) {
                        CreativeInventory::getInstance()->add($item);
                    }
                }
            }
        }
    }

    /**
     * @param string[] $stringToItemParserNames
     */
    private static function registerSimpleBlock(string $id, Block $block, array $stringToItemParserNames): void
    {
        RuntimeBlockStateRegistry::getInstance()->register($block);

        GlobalBlockStateHandlers::getDeserializer()->mapSimple($id, fn() => clone $block);
        GlobalBlockStateHandlers::getSerializer()->mapSimple($block, $id);

        foreach ($stringToItemParserNames as $name) {
            try {
                StringToItemParser::getInstance()->registerBlock($name, fn() => clone $block);
            } catch (InvalidArgumentException) {
                var_dump("Block already registered: $name"); // is there a way to debug log this? Must support both async and sync
                return;
            }
        }
        CreativeInventory::getInstance()->add($block->asItem());
    }

    /**
     * @param string[] $stringToItemParserNames
     */
    private static function registerSimpleItem(string $id, Item $item, array $stringToItemParserNames, bool $addToCreative = true): void
    {
        GlobalItemDataHandlers::getDeserializer()->map($id, fn() => clone $item);
        GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($id));

        foreach ($stringToItemParserNames as $name) {
            try {
                StringToItemParser::getInstance()->register($name, fn() => clone $item);
            } catch (InvalidArgumentException) {
                var_dump("Item already registered: $name"); // is there a way to debug log this? Must support both async and sync
                return;
            }
        }
        if ($addToCreative) {
            CreativeInventory::getInstance()->add($item);
        }
    }

    /**
     * @link ItemSerializerDeserializerRegistrar::map1to1ItemWithMeta()
     * @phpstan-template TItem of Item
     * @phpstan-param TItem $item
     * @phpstan-param Closure(TItem, int) : void $deserializeMeta
     * @phpstan-param Closure(TItem) : int $serializeMeta
     */
    private static function map1to1ItemWithMeta(string $id, Item $item, Closure $deserializeMeta, Closure $serializeMeta): void
    {
        GlobalItemDataHandlers::getDeserializer()->map($id, function (SavedItemData $data) use ($item, $deserializeMeta): Item {
            $result = clone $item;
            $deserializeMeta($result, $data->getMeta());
            return $result;
        });
        GlobalItemDataHandlers::getSerializer()->map($item, function (Item $item) use ($id, $serializeMeta): SavedItemData {
            $meta = $serializeMeta($item);
            return new SavedItemData($id, $meta);
        });
    }

    /**
     * @param string[] $blocks
     * @return void
     */
    private function registerDummyTiles(array $blocks): void
    {
        // Goal: preserve tile data, not the best way but good enough for decoration purpose
        // not going to write a separate tile for each block unless absolutely required :P
        // generic block registration: currently certain newly placed block will not have tile due to generic block registration
        $tiles = [
            TileNames::BEEHIVE => [BlockTypeNames::BEEHIVE, BlockTypeNames::BEE_NEST],
            TileNames::BRUSHABLE_BLOCK => [BlockTypeNames::SUSPICIOUS_GRAVEL, BlockTypeNames::SUSPICIOUS_SAND],
            TileNames::CALIBRATED_SCULK_SENSOR => [BlockTypeNames::CALIBRATED_SCULK_SENSOR],
            TileNames::CAMPFIRE => [BlockTypeNames::CAMPFIRE, BlockTypeNames::SOUL_CAMPFIRE],
            TileNames::CONDUIT => [BlockTypeNames::CONDUIT], // generic block registration, tile not important, activation is client-side, Active Byte 0 Target Long -1 isMovable 1
            TileNames::COMMAND_BLOCK => [BlockTypeNames::COMMAND_BLOCK, BlockTypeNames::CHAIN_COMMAND_BLOCK, BlockTypeNames::REPEATING_COMMAND_BLOCK],
            TileNames::CRAFTER => [BlockTypeNames::CRAFTER],
            TileNames::DECORATED_POT => [BlockTypeNames::DECORATED_POT],
            TileNames::DISPENSER => [BlockTypeNames::DISPENSER],
            TileNames::DROPPER => [BlockTypeNames::DROPPER],
            TileNames::END_GATEWAY => [BlockTypeNames::END_GATEWAY], // generic block registration, tile not important, not setBlock-able in vanilla, Age Int 0 ExitPortal List{Int,Int,Int}
            TileNames::END_PORTAL => [BlockTypeNames::END_PORTAL], // generic block registration, tile not important, isMovable 1
            TileNames::HANGING_SIGN => [
                BlockTypeNames::ACACIA_HANGING_SIGN,
                BlockTypeNames::BAMBOO_HANGING_SIGN,
                BlockTypeNames::BIRCH_HANGING_SIGN,
                BlockTypeNames::CHERRY_HANGING_SIGN,
                BlockTypeNames::CRIMSON_HANGING_SIGN,
                BlockTypeNames::DARK_OAK_HANGING_SIGN,
                BlockTypeNames::JUNGLE_HANGING_SIGN,
                BlockTypeNames::MANGROVE_HANGING_SIGN,
                BlockTypeNames::OAK_HANGING_SIGN,
                BlockTypeNames::SPRUCE_HANGING_SIGN,
                BlockTypeNames::WARPED_HANGING_SIGN,
            ],
            TileNames::JIGSAW_BLOCK => [BlockTypeNames::JIGSAW], // unknown tags, not setBlock-able in vanilla
            TileNames::LODESTONE => [BlockTypeNames::LODESTONE], // generic block registration, tile not important, isMovable 1
            TileNames::PISTON_ARM => [
                // default:         AttachedBlocks List BreakBlocks List LastProgress Float 0 NewState Byte 0 Progress Float 0 State Byte 0 Sticky Byte 0/1 isMovable 1
                // fully extended:  AttachedBlocks List BreakBlocks List LastProgress Float 1 NewState Byte 2 Progress Float 1 State Byte 2 Sticky Byte 0/1 isMovable 0, doesn't matter if piston_arm_collision is present
                BlockTypeNames::PISTON,
                BlockTypeNames::STICKY_PISTON,
            ],
            TileNames::SCULK_SENSOR => [BlockTypeNames::SCULK_SENSOR],
            TileNames::SCULK_SHRIEKER => [BlockTypeNames::SCULK_SHRIEKER],
            TileNames::SCULK_CATALYST => [BlockTypeNames::SCULK_CATALYST],
            TileNames::STRUCTURE_BLOCK => [BlockTypeNames::STRUCTURE_BLOCK],
            TileNames::TRIAL_SPAWNER => [BlockTypeNames::TRIAL_SPAWNER],
            TileNames::VAULT => [BlockTypeNames::VAULT]
        ];
        $registeredTiles = ReflectionHelper::TileFactoryRegisteredTileIds();
        foreach ($tiles as $name => $blockNames) {
            if (in_array($name, $registeredTiles, true)) {
                $this->getLogger()->debug("Tile $name is already registered!");
                unset($tiles[$name]);
                continue;
            }
            $need = false;
            foreach ($blockNames as $block) {
                if (in_array($block, $blocks, true)) {
                    $need = true;
                    break;
                }
            }
            if (!$need) {
                $this->getLogger()->debug((count($blockNames) > 1 ? "None of " . implode(',', $blockNames) . " is" : "$blockNames[0] is not") . " registered, not registering tile $name");
                unset($tiles[$name]);
            }
        }
        TileFactory::getInstance()->register(DummyTile::class, array_keys($tiles));
    }
}
