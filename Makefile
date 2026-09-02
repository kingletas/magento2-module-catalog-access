# commerce/module-catalog-access
#
# Run `make` with no arguments for the list.
#
# Checks run through `../dev/`, the shared harness that borrows an existing
# installation's vendor tree, when it is there; cloned on its own the package's
# own composer scripts are used instead, and `make install` then needs
# repo.magento.com credentials for `magento/framework`.

SHELL       := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c
.DEFAULT_GOAL := help

M2_VENDOR ?= $(HOME)/Development/magento/commerce-vanilla/vendor
PHP       ?= php
COMPOSER  ?= composer

# Which suite `make test` runs: all, unit, wiring, performance or behaviour.
SUITE ?= all

SUITE_all         := Test/Unit Test/Wiring Test/Performance Test/Behaviour
SUITE_unit        := Test/Unit
SUITE_wiring      := Test/Wiring
SUITE_performance := Test/Performance
SUITE_behaviour   := Test/Behaviour

SUITE_DIRS  := $(SUITE_$(SUITE))
SUITE_LABEL := $(if $(filter all,$(SUITE)),every suite,the $(SUITE) suite)

HARNESS := $(wildcard $(CURDIR)/../dev/run-tests.php)
PHPCS   := $(wildcard $(CURDIR)/../dev/run-phpcs.php)

.PHONY: help
help: ## Show this help
	@echo
	@echo "  commerce/module-catalog-access"
	@echo
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "    \033[36m%-12s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "  checked via $(if $(HARNESS),the shared harness in ../dev,this package's own composer scripts)"
	@echo

# --- install ----------------------------------------------------------------

.PHONY: install
install: ## Install this package's dev dependencies (needs repo.magento.com credentials)
	@$(COMPOSER) install

# --- checks -----------------------------------------------------------------

.PHONY: test
test: ## Run the suites; narrow with SUITE=unit|wiring|performance|behaviour
ifeq ($(SUITE_DIRS),)
	@echo "  unknown SUITE '$(SUITE)'. One of: all unit wiring performance behaviour"
	@echo
	@echo "      make test"
	@echo "      make test SUITE=behaviour"
	@echo
	@exit 2
else ifneq ($(HARNESS),)
	@M2_VENDOR="$(M2_VENDOR)" $(PHP) "$(HARNESS)" --configuration ../dev/phpunit.xml $(SUITE_DIRS)
else ifeq ($(SUITE),all)
	@$(COMPOSER) run-script test
else
	@$(COMPOSER) run-script test-$(SUITE)
endif

.PHONY: cs
cs: ## Coding standard
ifneq ($(PHPCS),)
	@M2_VENDOR="$(M2_VENDOR)" $(PHP) "$(PHPCS)" --standard=phpcs.xml.dist .
else
	@$(COMPOSER) run-script cs
endif

.PHONY: cs-fix
cs-fix: ## Fix what the coding standard can fix automatically
	@$(COMPOSER) run-script cs-fix

.PHONY: stan
stan: ## Static analysis
	@$(COMPOSER) run-script stan

.PHONY: md
md: ## Mess detection
	@$(COMPOSER) run-script md

.PHONY: lint
lint: ## Syntax check every PHP file
	@$(COMPOSER) run-script lint

.PHONY: check
check: cs test ## Everything a commit has to pass
	@echo
	@echo "  the standard and $(SUITE_LABEL) pass for catalog-access"
