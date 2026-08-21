# ACL Agent Rooms extension API

API version: `1`

The Free plugin deliberately exposes a small add-on surface. Add-ons must not replace Free services, register competing workers, inspect provider credentials, or patch private coordinator state.

## Bootstrap

`acl_agent_rooms_loaded`

Fires once after Free has registered its core runtime hooks. It receives one `ACL\AgentRooms\ExtensionApi` instance. An add-on should attach its callback when its main plugin file is loaded so plugin load order does not matter.

## Admin extension

`acl_agent_rooms_admin_menu_registered`

Fires while WordPress is building the Agent Rooms admin menu. It receives the parent menu slug and the same `ExtensionApi` instance. Add-ons may register a scoped submenu here.

## REST extension

`acl_agent_rooms_rest_routes_registered`

Fires after Free registers all routes. It receives the core namespace and the same `ExtensionApi` instance. Add-ons should use their own REST namespace and must provide explicit permission callbacks.

## `ExtensionApi`

The API provides:

- extension API, Free plugin, and Free database versions;
- the Free REST namespace and admin parent slug;
- stable capability names and the Free capability bridge;
- an allowlisted set of reporting table names for agents, Brain runs, events, jobs, maintenance runs, restrictions, rooms, and usage.

Reporting tables remain owned by Free. The allowlist is a read contract, not permission to mutate core data. Unknown capability or table keys throw `InvalidArgumentException` instead of accepting user-controlled identifiers.

## Optional Pro information URL

`acl_agent_rooms_pro_information_url`

Filters the optional official Pro information URL shown only on the Free settings page. The default is empty, so Free renders no fabricated or unconfigured external link.
