# Controllers

Controllers are high-level, use case-oriented entry points for Wikibase features, introduced as part of the modularization strategy described in [ADR 25](@ref adr_0025). Unlike [entity type definitions](@ref docs_topics_entitytypes), which define lower-level services orchestrated by generic Wikibase application logic, controllers own the full execution of a specific feature for a given entity type.

## Controller definitions

Controllers are registered per entity type in [WikibaseRepo.controllers.php](@ref WikibaseRepo.controllers.php). Extensions can add or modify controller definitions using the [WikibaseRepoControllers hook](@ref Wikibase::Repo::Hooks::WikibaseRepoControllersHook).

For each registered controller, there are typically four types of components:
* a controller interface, which defines the contract for a feature
* implementations of the controller interface for each entity type that supports the feature
* corresponding controller factory callbacks in the `*.controllers.php` file(s)
* a dispatcher which is used in the feature's entry point (e.g. a REST route handler) to delegate the request to an entity type-specific controller

Examples can be found by exploring usages of the [ControllerRegistry::get()](@ref Wikibase::Repo::ControllerRegistry::get()) method and the controller definition files such as [WikibaseRepo.controllers.php](@ref WikibaseRepo.controllers.php).

## Available controllers

* `wbsearchentities-controller`: entry point for the `wbsearchentities` Action API module for a given entity type.
