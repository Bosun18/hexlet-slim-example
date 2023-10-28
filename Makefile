start:
	php -S localhost:8081 -t public public/index.php
lint:
	composer exec --verbose phpcs -- --standard=PSR12 public src
install:
	composer install
validate:
	composer validate