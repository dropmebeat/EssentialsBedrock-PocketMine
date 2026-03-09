<?php

declare(strict_types=1);

namespace icrafts\essentialsbedrock;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_values;
use function count;
use function ctype_digit;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function ltrim;
use function max;
use function mb_strtolower;
use function str_contains;
use function str_replace;
use function strtolower;
use function time;
use function trim;

final class EssentialsBedrock extends PluginBase implements Listener
{
    private Config $homesConfig;
    private Config $warpsConfig;
    private Config $spawnConfig;
    private Config $kitsConfig;
    private Config $kitUsageConfig;

    /** @var array<string, array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}> */
    private array $backLocations = [];

    /** @var array<string, array{sender: string, type: string, expires: int}> */
    private array $tpRequests = [];

    /** @var array<string, string> */
    private array $lastMessagePartner = [];

    /** @var array<string, true> */
    private array $godMode = [];

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->saveResource("kits.yml");
        $this->homesConfig = new Config(
            $this->getDataFolder() . "homes.yml",
            Config::YAML,
            [],
        );
        $this->warpsConfig = new Config(
            $this->getDataFolder() . "warps.yml",
            Config::YAML,
            [],
        );
        $this->spawnConfig = new Config(
            $this->getDataFolder() . "spawn.yml",
            Config::YAML,
            [],
        );
        $this->kitsConfig = new Config(
            $this->getDataFolder() . "kits.yml",
            Config::YAML,
            ["kits" => []],
        );
        $this->kitUsageConfig = new Config(
            $this->getDataFolder() . "kit-usage.yml",
            Config::YAML,
            [],
        );

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function (): void {
                $this->purgeExpiredRequests();
            }),
            20,
        );

        $this->getLogger()->info("EssentialsBedrock enabled.");
    }

    public function onDisable(): void
    {
        $this->homesConfig->save();
        $this->warpsConfig->save();
        $this->spawnConfig->save();
        $this->kitsConfig->save();
        $this->kitUsageConfig->save();
    }

    public function onPlayerTeleport(EntityTeleportEvent $event): void
    {
        $player = $event->getEntity();
        if (!$player instanceof Player) {
            return;
        }
        $this->backLocations[
            $player->getUniqueId()->toString()
        ] = $this->serializeLocation($event->getFrom());
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void
    {
        $player = $event->getPlayer();
        $this->backLocations[
            $player->getUniqueId()->toString()
        ] = $this->serializeLocation($player->getLocation());
    }

    public function onEntityDamage(EntityDamageEvent $event): void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }
        $uuid = $entity->getUniqueId()->toString();
        if (isset($this->godMode[$uuid])) {
            $event->cancel();
        }
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $uuid = $event->getPlayer()->getUniqueId()->toString();
        unset($this->tpRequests[$uuid]);
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args,
    ): bool {
        $name = strtolower($command->getName());

        return match ($name) {
            "setspawn" => $this->handleSetSpawn($sender),
            "spawn" => $this->handleSpawn($sender),
            "sethome" => $this->handleSetHome($sender, $args),
            "home" => $this->handleHome($sender, $args),
            "delhome" => $this->handleDelHome($sender, $args),
            "homes" => $this->handleHomes($sender),
            "setwarp" => $this->handleSetWarp($sender, $args),
            "warp" => $this->handleWarp($sender, $args),
            "delwarp" => $this->handleDelWarp($sender, $args),
            "warps" => $this->handleWarps($sender),
            "back" => $this->handleBack($sender),
            "tpa" => $this->handleTpa($sender, $args, "to"),
            "tpahere" => $this->handleTpa($sender, $args, "here"),
            "tpaccept" => $this->handleTpAnswer($sender, true),
            "tpdeny" => $this->handleTpAnswer($sender, false),
            "msg", "tell", "w" => $this->handleMsg($sender, $args),
            "r", "reply" => $this->handleReply($sender, $args),
            "heal" => $this->handleHeal($sender, $args),
            "feed" => $this->handleFeed($sender, $args),
            "fly" => $this->handleFly($sender, $args),
            "god" => $this->handleGod($sender, $args),
            "kit" => $this->handleKit($sender, $args),
            "kits" => $this->handleKits($sender),
            default => false,
        };
    }

    private function handleSetSpawn(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $this->spawnConfig->set(
            "spawn",
            $this->serializeLocation($sender->getLocation()),
        );
        $this->spawnConfig->save();
        $this->sendLang($sender, "spawn-set");
        return true;
    }

    private function handleSpawn(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $data = $this->spawnConfig->get("spawn", []);
        if (!is_array($data)) {
            $this->sendLang($sender, "warp-not-found", ["%name%" => "spawn"]);
            return true;
        }
        $location = $this->deserializeLocation($data);
        if ($location === null) {
            $this->sendLang($sender, "world-not-loaded");
            return true;
        }
        $sender->teleport($location);
        $this->sendLang($sender, "teleported");
        return true;
    }

    private function handleSetHome(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $name = $this->normalizeName(
            (string) ($args[0] ??
                $this->getConfig()->getNested("homes.default-name", "home")),
        );
        $uuid = $sender->getUniqueId()->toString();
        $homes = $this->homesConfig->get($uuid, []);
        if (!is_array($homes)) {
            $homes = [];
        }
        $isNew = !array_key_exists($name, $homes);
        $maxHomes = max(
            1,
            (int) $this->getConfig()->getNested("homes.max-per-player", 5),
        );
        if ($isNew && count($homes) >= $maxHomes) {
            $sender->sendMessage(
                TextFormat::colorize(
                    $this->replaceVars(
                        "%prefix%&cHome limit reached: &e%max%&c.",
                        ["%max%" => (string) $maxHomes],
                    ),
                ),
            );
            return true;
        }
        $homes[$name] = $this->serializeLocation($sender->getLocation());
        $this->homesConfig->set($uuid, $homes);
        $this->homesConfig->save();
        $this->sendLang($sender, "home-set", ["%name%" => $name]);
        return true;
    }

    private function handleHome(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $name = $this->normalizeName(
            (string) ($args[0] ??
                $this->getConfig()->getNested("homes.default-name", "home")),
        );
        $uuid = $sender->getUniqueId()->toString();
        $homes = $this->homesConfig->get($uuid, []);
        if (
            !is_array($homes) ||
            !isset($homes[$name]) ||
            !is_array($homes[$name])
        ) {
            $this->sendLang($sender, "home-not-found", ["%name%" => $name]);
            return true;
        }
        $location = $this->deserializeLocation($homes[$name]);
        if ($location === null) {
            $this->sendLang($sender, "world-not-loaded");
            return true;
        }
        $sender->teleport($location);
        $this->sendLang($sender, "teleported");
        return true;
    }

    private function handleDelHome(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $name = $this->normalizeName(
            (string) ($args[0] ??
                $this->getConfig()->getNested("homes.default-name", "home")),
        );
        $uuid = $sender->getUniqueId()->toString();
        $homes = $this->homesConfig->get($uuid, []);
        if (!is_array($homes) || !isset($homes[$name])) {
            $this->sendLang($sender, "home-not-found", ["%name%" => $name]);
            return true;
        }
        unset($homes[$name]);
        $this->homesConfig->set($uuid, $homes);
        $this->homesConfig->save();
        $this->sendLang($sender, "home-deleted", ["%name%" => $name]);
        return true;
    }

    private function handleHomes(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $homes = $this->homesConfig->get(
            $sender->getUniqueId()->toString(),
            [],
        );
        if (!is_array($homes) || $homes === []) {
            $this->sendLang($sender, "homes-list", ["%list%" => "-"]);
            return true;
        }
        $this->sendLang($sender, "homes-list", [
            "%list%" => implode(", ", array_keys($homes)),
        ]);
        return true;
    }

    private function handleSetWarp(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $name = $this->normalizeName((string) ($args[0] ?? ""));
        if ($name === "") {
            $sender->sendMessage($commandUsage("/setwarp <name>"));
            return true;
        }
        $this->warpsConfig->set(
            $name,
            $this->serializeLocation($sender->getLocation()),
        );
        $this->warpsConfig->save();
        $this->sendLang($sender, "warp-set", ["%name%" => $name]);
        return true;
    }

    private function handleWarp(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $name = $this->normalizeName((string) ($args[0] ?? ""));
        if ($name === "") {
            $sender->sendMessage($commandUsage("/warp <name>"));
            return true;
        }
        $data = $this->warpsConfig->get($name, null);
        if (!is_array($data)) {
            $this->sendLang($sender, "warp-not-found", ["%name%" => $name]);
            return true;
        }
        $location = $this->deserializeLocation($data);
        if ($location === null) {
            $this->sendLang($sender, "world-not-loaded");
            return true;
        }
        $sender->teleport($location);
        $this->sendLang($sender, "teleported");
        return true;
    }

    private function handleDelWarp(CommandSender $sender, array $args): bool
    {
        $name = $this->normalizeName((string) ($args[0] ?? ""));
        if ($name === "") {
            $sender->sendMessage($commandUsage("/delwarp <name>"));
            return true;
        }
        if (!$this->warpsConfig->exists($name)) {
            $this->sendLang($sender, "warp-not-found", ["%name%" => $name]);
            return true;
        }
        $this->warpsConfig->remove($name);
        $this->warpsConfig->save();
        $this->sendLang($sender, "warp-deleted", ["%name%" => $name]);
        return true;
    }

    private function handleWarps(CommandSender $sender): bool
    {
        $warps = $this->warpsConfig->getAll();
        $this->sendLang($sender, "warps-list", [
            "%list%" => $warps === [] ? "-" : implode(", ", array_keys($warps)),
        ]);
        return true;
    }

    private function handleBack(CommandSender $sender): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $uuid = $sender->getUniqueId()->toString();
        if (!isset($this->backLocations[$uuid])) {
            $this->sendLang($sender, "back-not-available");
            return true;
        }
        $location = $this->deserializeLocation($this->backLocations[$uuid]);
        if ($location === null) {
            $this->sendLang($sender, "world-not-loaded");
            return true;
        }
        $sender->teleport($location);
        $this->sendLang($sender, "teleported");
        return true;
    }

    private function handleTpa(
        CommandSender $sender,
        array $args,
        string $type,
    ): bool {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $targetName = (string) ($args[0] ?? "");
        $target = $this->findOnlinePlayer($targetName);
        if ($target === null || $target === $sender) {
            $this->sendLang($sender, "player-not-found");
            return true;
        }

        $timeout = max(
            5,
            (int) $this->getConfig()->getNested("tpa.timeout-seconds", 60),
        );
        $this->tpRequests[$target->getUniqueId()->toString()] = [
            "sender" => $sender->getUniqueId()->toString(),
            "type" => $type,
            "expires" => time() + $timeout,
        ];

        if ($type === "here") {
            $this->sendLang($sender, "tpahere-sent", [
                "%player%" => $target->getName(),
            ]);
            $this->sendLang($target, "tpahere-received", [
                "%player%" => $sender->getName(),
            ]);
        } else {
            $this->sendLang($sender, "tpa-sent", [
                "%player%" => $target->getName(),
            ]);
            $this->sendLang($target, "tpa-received", [
                "%player%" => $sender->getName(),
            ]);
        }
        return true;
    }

    private function handleTpAnswer(CommandSender $sender, bool $accept): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $targetUuid = $sender->getUniqueId()->toString();
        $request = $this->tpRequests[$targetUuid] ?? null;
        if ($request === null) {
            $this->sendLang($sender, "no-pending-request");
            return true;
        }
        unset($this->tpRequests[$targetUuid]);

        $senderPlayer = $this->findOnlinePlayerByUuid($request["sender"]);
        if ($senderPlayer === null) {
            $this->sendLang($sender, "player-not-found");
            return true;
        }
        if ($request["expires"] < time()) {
            $this->sendLang($sender, "tpa-expired");
            $this->sendLang($senderPlayer, "tpa-expired");
            return true;
        }
        if (!$accept) {
            $this->sendLang($sender, "tpa-denied-target", [
                "%player%" => $senderPlayer->getName(),
            ]);
            $this->sendLang($senderPlayer, "tpa-denied-sender", [
                "%player%" => $sender->getName(),
            ]);
            return true;
        }

        if ($request["type"] === "here") {
            // /tpahere <player>: acceptor teleports to requester
            $sender->teleport($senderPlayer->getLocation());
        } else {
            // /tpa <player>: requester teleports to acceptor
            $senderPlayer->teleport($sender->getLocation());
        }
        $this->sendLang($sender, "tpa-accepted-target", [
            "%player%" => $senderPlayer->getName(),
        ]);
        $this->sendLang($senderPlayer, "tpa-accepted-sender", [
            "%player%" => $sender->getName(),
        ]);
        return true;
    }

    private function handleMsg(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $targetName = (string) ($args[0] ?? "");
        $message = trim(implode(" ", array_slice($args, 1)));
        if ($targetName === "" || $message === "") {
            $sender->sendMessage($commandUsage("/msg <player> <message>"));
            return true;
        }
        $target = $this->findOnlinePlayer($targetName);
        if ($target === null || $target === $sender) {
            $this->sendLang($sender, "player-not-found");
            return true;
        }

        $sender->sendMessage(
            TextFormat::colorize(
                $this->replaceVars(
                    (string) $this->getConfig()->getNested(
                        "messages.msg-format-sender",
                        "",
                    ),
                    [
                        "%player%" => $target->getName(),
                        "%message%" => $message,
                    ],
                ),
            ),
        );
        $target->sendMessage(
            TextFormat::colorize(
                $this->replaceVars(
                    (string) $this->getConfig()->getNested(
                        "messages.msg-format-target",
                        "",
                    ),
                    [
                        "%player%" => $sender->getName(),
                        "%message%" => $message,
                    ],
                ),
            ),
        );

        $this->lastMessagePartner[$sender->getUniqueId()->toString()] = $target
            ->getUniqueId()
            ->toString();
        $this->lastMessagePartner[$target->getUniqueId()->toString()] = $sender
            ->getUniqueId()
            ->toString();
        return true;
    }

    private function handleReply(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $this->sendLang($sender, "player-only");
            return true;
        }
        $message = trim(implode(" ", $args));
        if ($message === "") {
            $sender->sendMessage($commandUsage("/r <message>"));
            return true;
        }
        $partnerUuid =
            $this->lastMessagePartner[$sender->getUniqueId()->toString()] ??
            null;
        if ($partnerUuid === null) {
            $this->sendLang($sender, "no-reply-target");
            return true;
        }
        $target = $this->findOnlinePlayerByUuid($partnerUuid);
        if ($target === null) {
            $this->sendLang($sender, "player-not-found");
            return true;
        }

        $sender->sendMessage(
            TextFormat::colorize(
                $this->replaceVars(
                    (string) $this->getConfig()->getNested(
                        "messages.msg-format-sender",
                        "",
                    ),
                    [
                        "%player%" => $target->getName(),
                        "%message%" => $message,
                    ],
                ),
            ),
        );
        $target->sendMessage(
            TextFormat::colorize(
                $this->replaceVars(
                    (string) $this->getConfig()->getNested(
                        "messages.msg-format-target",
                        "",
                    ),
                    [
                        "%player%" => $sender->getName(),
                        "%message%" => $message,
                    ],
                ),
            ),
        );

        return true;
    }

    private function handleHeal(CommandSender $sender, array $args): bool
    {
        $target = $this->resolveTargetPlayer(
            $sender,
            $args,
            "essentialsbedrock.heal.others",
        );
        if ($target === null) {
            return true;
        }
        $target->setHealth($target->getMaxHealth());
        $this->sendLang($sender, "heal-done", [
            "%player%" => $target->getName(),
        ]);
        if ($target !== $sender) {
            $this->sendLang($target, "heal-done", [
                "%player%" => $target->getName(),
            ]);
        }
        return true;
    }

    private function handleFeed(CommandSender $sender, array $args): bool
    {
        $target = $this->resolveTargetPlayer(
            $sender,
            $args,
            "essentialsbedrock.feed.others",
        );
        if ($target === null) {
            return true;
        }
        $target->getHungerManager()->setFood(20);
        $target->getHungerManager()->setSaturation(20.0);
        $this->sendLang($sender, "feed-done", [
            "%player%" => $target->getName(),
        ]);
        if ($target !== $sender) {
            $this->sendLang($target, "feed-done", [
                "%player%" => $target->getName(),
            ]);
        }
        return true;
    }

    private function handleFly(CommandSender $sender, array $args): bool
    {
        $target = $this->resolveTargetPlayer(
            $sender,
            $args,
            "essentialsbedrock.fly.others",
        );
        if ($target === null) {
            return true;
        }
        $enabled = !$target->getAllowFlight();
        $target->setAllowFlight($enabled);
        if (!$enabled) {
            $target->setFlying(false);
            $target->resetFallDistance();
        }
        $this->sendLang($sender, $enabled ? "fly-enabled" : "fly-disabled", [
            "%player%" => $target->getName(),
        ]);
        if ($target !== $sender) {
            $this->sendLang(
                $target,
                $enabled ? "fly-enabled" : "fly-disabled",
                ["%player%" => $target->getName()],
            );
        }
        return true;
    }

    private function handleGod(CommandSender $sender, array $args): bool
    {
        $target = $this->resolveTargetPlayer(
            $sender,
            $args,
            "essentialsbedrock.god.others",
        );
        if ($target === null) {
            return true;
        }
        $uuid = $target->getUniqueId()->toString();
        $enabled = !isset($this->godMode[$uuid]);
        if ($enabled) {
            $this->godMode[$uuid] = true;
        } else {
            unset($this->godMode[$uuid]);
        }
        $this->sendLang($sender, $enabled ? "god-enabled" : "god-disabled", [
            "%player%" => $target->getName(),
        ]);
        if ($target !== $sender) {
            $this->sendLang(
                $target,
                $enabled ? "god-enabled" : "god-disabled",
                ["%player%" => $target->getName()],
            );
        }
        return true;
    }

    private function handleKits(CommandSender $sender): bool
    {
        $kits = $this->getAllKits();
        if ($kits === []) {
            $this->sendKitText($sender, "No kits configured.");
            return true;
        }

        $list = [];
        foreach ($kits as $kitName => $_kitData) {
            if (
                $sender instanceof Player &&
                !$sender->hasPermission("essentialsbedrock.kits." . $kitName)
            ) {
                continue;
            }
            $list[] = $kitName;
        }

        if ($list === []) {
            $this->sendKitText($sender, "No kits available.");
            return true;
        }

        $this->sendKitText($sender, "Kits: " . implode(", ", $list));
        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleKit(CommandSender $sender, array $args): bool
    {
        if (!$sender->hasPermission("essentialsbedrock.kit")) {
            $this->sendLang($sender, "no-permission");
            return true;
        }

        if (count($args) < 1) {
            return $this->handleKits($sender);
        }

        $kitName = $this->normalizeName((string) $args[0]);
        $kit = $this->getKit($kitName);
        if ($kit === null) {
            $this->sendKitText($sender, "Kit not found: " . $kitName);
            return true;
        }

        $target = null;
        if (isset($args[1])) {
            if (!$sender->hasPermission("essentialsbedrock.kit.others")) {
                $this->sendLang($sender, "no-permission");
                return true;
            }
            $target = $this->findOnlinePlayer((string) $args[1]);
            if ($target === null) {
                $this->sendLang($sender, "player-not-found");
                return true;
            }
        } elseif ($sender instanceof Player) {
            $target = $sender;
        } else {
            $this->sendKitText($sender, "Usage: /kit <name> <player>");
            return true;
        }

        if (!$sender->hasPermission("essentialsbedrock.kits." . $kitName)) {
            $this->sendKitText($sender, "No permission for kit: " . $kitName);
            return true;
        }

        $delay = max(0, (int) ($kit["delay"] ?? 0));
        $cooldownUser = $sender instanceof Player ? $sender : $target;
        $remain = $this->getKitCooldownRemaining(
            $cooldownUser,
            $kitName,
            $delay,
        );
        if ($remain > 0) {
            $this->sendKitText(
                $sender,
                "Kit " . $kitName . " cooldown: " . $remain . " sec.",
            );
            return true;
        }

        $this->giveKit($target, $kit);
        $this->setKitUsedNow($cooldownUser, $kitName);

        if ($sender !== $target) {
            $this->sendKitText(
                $sender,
                "Kit " . $kitName . " given to " . $target->getName() . ".",
            );
        }
        $this->sendKitText($target, "You received kit: " . $kitName . ".");
        return true;
    }

    /**
     * @param array<string, mixed> $kit
     */
    private function giveKit(Player $target, array $kit): void
    {
        $items = $kit["items"] ?? [];
        if (is_array($items)) {
            foreach ($items as $spec) {
                if (!is_string($spec)) {
                    continue;
                }
                $parsed = $this->parseKitItemSpec($spec);
                if ($parsed === null) {
                    continue;
                }
                $item = StringToItemParser::getInstance()->parse($parsed["id"]);
                if ($item === null && !str_contains($parsed["id"], ":")) {
                    $item = StringToItemParser::getInstance()->parse(
                        "minecraft:" . $parsed["id"],
                    );
                }
                if ($item === null) {
                    continue;
                }
                $item->setCount($parsed["count"]);
                $leftovers = $target->getInventory()->addItem($item);
                foreach ($leftovers as $leftover) {
                    $target
                        ->getWorld()
                        ->dropItem($target->getPosition(), $leftover);
                }
            }
        }

        $commands = $kit["commands"] ?? [];
        if (is_array($commands)) {
            foreach ($commands as $command) {
                if (!is_string($command) || trim($command) === "") {
                    continue;
                }
                $cmd = str_replace("{player}", $target->getName(), $command);
                $this->getServer()->dispatchCommand(
                    $this->getServer()->getConsoleSender(),
                    trim(ltrim($cmd, "/")),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getAllKits(): array
    {
        $raw = $this->kitsConfig->get("kits", []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $data) {
            if (!is_array($data)) {
                continue;
            }
            $out[$this->normalizeName((string) $name)] = $data;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getKit(string $kitName): ?array
    {
        $all = $this->getAllKits();
        $kit = $all[$kitName] ?? null;
        return is_array($kit) ? $kit : null;
    }

    private function getKitCooldownRemaining(
        Player $player,
        string $kitName,
        int $delay,
    ): int {
        if ($delay <= 0) {
            return 0;
        }
        if ($player->hasPermission("essentialsbedrock.kit.bypasscooldown")) {
            return 0;
        }

        $playerKey = $this->normalizeName($player->getName());
        $used = $this->kitUsageConfig->getNested(
            $playerKey . "." . $kitName,
            0,
        );
        $next = (int) $used + $delay;
        return max(0, $next - time());
    }

    private function setKitUsedNow(Player $player, string $kitName): void
    {
        $playerKey = $this->normalizeName($player->getName());
        $this->kitUsageConfig->setNested($playerKey . "." . $kitName, time());
        $this->kitUsageConfig->save();
    }

    /**
     * @return array{id:string,count:int}|null
     */
    private function parseKitItemSpec(string $spec): ?array
    {
        $raw = trim($spec);
        if ($raw === "") {
            return null;
        }

        if (str_contains($raw, " ")) {
            $parts = explode(" ", $raw);
            $id = trim((string) ($parts[0] ?? ""));
            $countRaw = trim((string) ($parts[1] ?? "1"));
            $count = ctype_digit($countRaw) ? max(1, (int) $countRaw) : 1;
            return $id === "" ? null : ["id" => $id, "count" => $count];
        }

        $id = $raw;
        $count = 1;
        $parts = explode(":", $raw);
        if (count($parts) >= 2) {
            $last = trim((string) $parts[count($parts) - 1]);
            if ($last !== "" && ctype_digit($last)) {
                $count = max(1, (int) $last);
                array_pop($parts);
                $id = implode(":", $parts);
            }
        }

        $id = trim($id);
        if ($id === "") {
            return null;
        }
        return ["id" => $id, "count" => $count];
    }

    private function sendKitText(CommandSender $sender, string $text): void
    {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $sender->sendMessage(TextFormat::colorize($prefix . "&r" . $text));
    }

    private function resolveTargetPlayer(
        CommandSender $sender,
        array $args,
        string $othersPermission,
    ): ?Player {
        $name = (string) ($args[0] ?? "");
        if ($name === "") {
            if (!$sender instanceof Player) {
                $this->sendLang($sender, "player-only");
                return null;
            }
            return $sender;
        }
        if (!$sender->hasPermission($othersPermission)) {
            $this->sendLang($sender, "no-permission");
            return null;
        }
        $target = $this->findOnlinePlayer($name);
        if ($target === null) {
            $this->sendLang($sender, "player-not-found");
            return null;
        }
        return $target;
    }

    private function purgeExpiredRequests(): void
    {
        $now = time();
        foreach ($this->tpRequests as $targetUuid => $request) {
            if ($request["expires"] >= $now) {
                continue;
            }
            unset($this->tpRequests[$targetUuid]);
            $target = $this->findOnlinePlayerByUuid($targetUuid);
            $sender = $this->findOnlinePlayerByUuid($request["sender"]);
            if ($target !== null) {
                $this->sendLang($target, "tpa-expired");
            }
            if ($sender !== null) {
                $this->sendLang($sender, "tpa-expired");
            }
        }
    }

    /**
     * @return array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}
     */
    private function serializeLocation(Position|Location $location): array
    {
        $yaw = $location instanceof Location ? $location->getYaw() : 0.0;
        $pitch = $location instanceof Location ? $location->getPitch() : 0.0;
        return [
            "world" => $location->getWorld()->getFolderName(),
            "x" => $location->getX(),
            "y" => $location->getY(),
            "z" => $location->getZ(),
            "yaw" => $yaw,
            "pitch" => $pitch,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserializeLocation(array $data): ?Location
    {
        $worldName = (string) ($data["world"] ?? "");
        $worldManager = $this->getServer()->getWorldManager();
        $world = $worldManager->getWorldByName($worldName);
        if ($world === null && $worldName !== "") {
            // Try to load the target world if it exists but is not loaded yet.
            $worldManager->loadWorld($worldName);
            $world = $worldManager->getWorldByName($worldName);
        }
        if ($world === null) {
            // Fallback to default world to avoid hard failure of /spawn.
            $world = $worldManager->getDefaultWorld();
        }
        if ($world === null) {
            return null;
        }
        return new Location(
            (float) ($data["x"] ?? 0.0),
            (float) ($data["y"] ?? 0.0),
            (float) ($data["z"] ?? 0.0),
            $world,
            (float) ($data["yaw"] ?? 0.0),
            (float) ($data["pitch"] ?? 0.0),
        );
    }

    private function findOnlinePlayer(string $name): ?Player
    {
        $name = trim($name);
        if ($name === "") {
            return null;
        }
        $player = $this->getServer()->getPlayerExact($name);
        if ($player !== null) {
            return $player;
        }
        return $this->getServer()->getPlayerByPrefix($name);
    }

    private function findOnlinePlayerByUuid(string $uuid): ?Player
    {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($player->getUniqueId()->toString() === $uuid) {
                return $player;
            }
        }
        return null;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param array<string, string> $replace
     */
    private function sendLang(
        CommandSender $sender,
        string $key,
        array $replace = [],
    ): void {
        $template = (string) $this->getConfig()->getNested(
            "messages." . $key,
            "",
        );
        if ($template === "") {
            return;
        }
        $sender->sendMessage(
            TextFormat::colorize($this->replaceVars($template, $replace)),
        );
    }

    /**
     * @param array<string, string> $replace
     */
    private function replaceVars(string $text, array $replace): string
    {
        $prefix = (string) $this->getConfig()->getNested("messages.prefix", "");
        $text = str_replace("%prefix%", $prefix, $text);
        foreach ($replace as $k => $v) {
            $text = str_replace($k, $v, $text);
        }
        return $text;
    }
}

function commandUsage(string $usage): string
{
    return TextFormat::colorize("&cUsage: &e" . $usage);
}
