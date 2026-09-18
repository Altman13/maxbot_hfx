ORG ?= g.khromov
PROJECT ?= tgbot_hfx
PACKAGE = https://armorjack.gitlab.yandexcloud.net//${ORG}/${PROJECT}
PHP_IMAGE ?= dockerhub.movemoveservice.ru/php:v8.3

.PHONY: composer
composer:

	docker run \
		-v `pwd`:/projects \
		-w /projects \
		-i ${PHP_IMAGE} \
		/bin/sh -c "composer install"

.PHONY: composer-dump-autoload
composer-dump-autoload:

	docker run \
		-v `pwd`:/projects \
		-w /projects \
		-i ${PHP_IMAGE} \
		/bin/sh -c "composer dump-autoload"

.PHONY: clear
clear:

	docker container prune -f
