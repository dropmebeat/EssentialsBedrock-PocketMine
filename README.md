# Essentials

**Essentials** is a comprehensive "all-in-one" utility suite for **PocketMine-MP**. It provides hundreds of essential commands and features that are crucial for managing a modern Minecraft server, from teleportation to moderation tools.

## Features

*   **Teleportation Suite:** Includes `/tp`, `/sethome`, `/home`, `/warp`, and `/back`.
*   **Moderation Tools:** Built-in systems for `/kick`, `/ban`, `/mute`, and `/invsee`.
*   **Player Utilities:** Essential commands like `/feed`, `/heal`, `/fly`, `/nick`, and `/god`.
*   **Economy Ready:** Seamlessly integrates with major economy providers for paid commands.
*   **Custom Kits:** Create and manage starter kits for your players.
*   **World Management:** Basic tools for managing time, weather, and world properties.
*   **Highly Flexible Permissions:** Every command is tied to a specific permission node.

## Essential Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/sethome` | Set your home location | `essentials.home.set` |
| `/warp` | Teleport to a public warp | `essentials.warp.use` |
| `/fly` | Toggle flight mode | `essentials.fly` |
| `/heal` | Restore player health | `essentials.heal` |
| `/sudo` | Run a command as another player | `essentials.admin.sudo` |

## Configuration Preview

```yaml
# Essentials Settings
homes:
  limit: 3 # Max homes per player
  
teleport:
  delay: 3 # Seconds before teleport
  cooldown: 10
  
chat:
  format: "<{DISPLAYNAME}> {MESSAGE}"
