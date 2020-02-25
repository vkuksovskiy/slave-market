-- mysql 8

-- Выбран способ хранения категорий - список смежностей, поскольку прост в реализации и не требует дополнительных обработок
CREATE TABLE categories (
	id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	parent_id BIGINT UNSIGNED NULL DEFAULT NULL,
	title VARCHAR(255) NOT NULL,

	CONSTRAINT categories_categories_fk FOREIGN KEY (parent_id) REFERENCES categories (id),
	INDEX(parent_id)
);

-- Рабу можно присвоить категорию любого уровня, но можно и только в листовую
CREATE TABLE slaves (
	id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
	price DECIMAL(10,2) UNSIGNED NOT NULL,
	weight SMALLINT UNSIGNED NOT NULL,
	gender BIT NOT NULL COMMENT '0 - female, 1 - male',

	price_per_hour DECIMAL(10,2) UNSIGNED NOT NULL,
	skin_color VARCHAR(100) NOT NULL DEFAULT '',
	description TEXT NOT NULL DEFAULT '',

	INDEX(price),
	INDEX(weight),
	INDEX(gender)
);

CREATE TABLE category_slave (
	slave_id BIGINT UNSIGNED NOT NULL,
	category_id BIGINT UNSIGNED NOT NULL,

	CONSTRAINT slaves_fk FOREIGN KEY (slave_id) REFERENCES slaves (id),
	CONSTRAINT categories_fk FOREIGN KEY (category_id) REFERENCES categories (id),
	PRIMARY KEY(slave_id, category_id)
);

-- Получить минимальную, максимальную и среднюю стоимость всех рабов весом более 60 кг.
SELECT MIN(price) AS min_price, MAX(price) AS max_price, AVG(price) AS average_price
FROM slaves
WHERE weight > 60;

-- Выбрать категории, в которых больше 10 рабов.
SELECT categories.title
FROM categories
JOIN category_slave ON categories.id = category_slave.category_id
GROUP BY categories.id
HAVING COUNT(*) > 10;

-- Выбрать категорию с наибольшей суммарной стоимостью рабов.
SELECT categories.title
FROM categories
JOIN category_slave ON categories.id = category_slave.category_id
JOIN slaves ON slaves.id = category_slave.slave_id
GROUP BY categories.id
ORDER BY SUM(slaves.price) DESC
LIMIT 1;

-- Выбрать категории, в которых мужчин больше чем женщин.
SELECT title
FROM categories
JOIN (
	SELECT category_slave.category_id as category_id, COUNT(*) AS men_count
	FROM slaves
	JOIN category_slave ON slaves.id = category_slave.slave_id
	WHERE gender = 1
	GROUP BY category_id
) AS men ON categories.id = men.category_id
JOIN (
	SELECT category_slave.category_id as category_id, COUNT(*) AS women_count
	FROM slaves
	JOIN category_slave ON slaves.id = category_slave.slave_id
	WHERE gender = 0
	GROUP BY category_id
) AS women ON categories.id = women.category_id
WHERE men_count > women_count;

-- Количество рабов в категории "Для кухни" (включая все вложенные категории).
WITH RECURSIVE rec_categories (id, parent_id) AS (
	SELECT id, parent_id
	FROM categories
	WHERE title = 'Для кухни'

	UNION ALL

	SELECT c.id, c.parent_id
	FROM categories c
	JOIN rec_categories ON c.parent_id = rec_categories.id
)
SELECT SUM(category_id) AS slaves_number
FROM category_slave
WHERE category_id IN (
	SELECT id
	FROM rec_categories
);
