<?php

namespace daos\sqlite;

/**
 * Base class for database access -- sqlite
 *
 * @copyright   Copyright (c) Harald Lapp (harald.lapp@gmail.com)
 * @license     GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @author      Harald Lapp (harald.lapp@gmail.com)
 * @author      Tobias Zeising <tobias.zeising@aditu.de>
 */
class Database {
    /** @var bool indicates whether database connection was initialized */
    private static $initialized = false;

    /**
     * establish connection and create undefined tables
     *
     * @return  void
     */
    public function __construct() {
        if (self::$initialized === false) {
            $db_file = \F3::get('db_file');

            // create empty database file if it does not exist
            if (!is_file($db_file)) {
                touch($db_file);
            }

            \F3::get('logger')->debug('Establish database connection');
            \F3::set('db', new \DB\SQL(
                'sqlite:' . $db_file
            ));

            // create tables if necessary
            $result = @\F3::get('db')->exec('SELECT name FROM sqlite_master WHERE type = "table"');
            $tables = [];
            foreach ($result as $table) {
                foreach ($table as $key => $value) {
                    $tables[] = $value;
                }
            }

            if (!in_array('items', $tables, true)) {
                \F3::get('db')->exec('
                    CREATE TABLE items (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        datetime    DATETIME NOT NULL,
                        title       TEXT NOT NULL,
                        content     TEXT NOT NULL,
                        thumbnail   TEXT,
                        icon        TEXT,
                        unread      BOOL NOT NULL,
                        starred     BOOL NOT NULL,
                        source      INT NOT NULL,
                        uid         VARCHAR(255) NOT NULL,
                        link        TEXT NOT NULL,
                        updatetime  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        author      VARCHAR(255)
                    );
                ');

                \F3::get('db')->exec('
                    CREATE INDEX source ON items (
                        source
                    );
                ');
                \F3::get('db')->exec('
                    CREATE TRIGGER update_updatetime_trigger
                    AFTER UPDATE ON items FOR EACH ROW
                        BEGIN
                            UPDATE items
                            SET updatetime = CURRENT_TIMESTAMP
                            WHERE id = NEW.id;
                        END;
                 ');
            }

            $isNewestSourcesTable = false;
            if (!in_array('sources', $tables, true)) {
                \F3::get('db')->exec('
                    CREATE TABLE sources (
                        id          INTEGER PRIMARY KEY AUTOINCREMENT,
                        title       TEXT NOT NULL,
                        tags        TEXT,
                        spout       TEXT NOT NULL,
                        params      TEXT NOT NULL,
                        filter      TEXT,
                        error       TEXT,
                        lastupdate  INTEGER,
                		lastentry   INTEGER
                    );
                ');
                $isNewestSourcesTable = true;
            }

            // version 1
            if (!in_array('version', $tables, true)) {
                \F3::get('db')->exec('
                    CREATE TABLE version (
                        version INT
                    );
                ');

                \F3::get('db')->exec('
                    INSERT INTO version (version) VALUES (8);
                ');

                \F3::get('db')->exec('
                    CREATE TABLE tags (
                        tag         TEXT NOT NULL,
                        color       TEXT NOT NULL
                    );
                ');

                if ($isNewestSourcesTable === false) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD tags TEXT;
                    ');
                }
            } else {
                $version = @\F3::get('db')->exec('SELECT version FROM version ORDER BY version DESC LIMIT 0, 1');
                $version = $version[0]['version'];

                if (strnatcmp($version, '3') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD lastupdate INT;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (3);
                    ');
                }
                if (strnatcmp($version, '4') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE items ADD updatetime DATETIME;
                    ');
                    \F3::get('db')->exec('
                        CREATE TRIGGER insert_updatetime_trigger
                        AFTER INSERT ON items FOR EACH ROW
                            BEGIN
                                UPDATE items
                                SET updatetime = CURRENT_TIMESTAMP
                                WHERE id = NEW.id;
                            END;
                    ');
                    \F3::get('db')->exec('
                        CREATE TRIGGER update_updatetime_trigger
                        AFTER UPDATE ON items FOR EACH ROW
                            BEGIN
                                UPDATE items
                                SET updatetime = CURRENT_TIMESTAMP
                                WHERE id = NEW.id;
                            END;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (4);
                    ');
                }
                if (strnatcmp($version, '5') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE items ADD author VARCHAR(255);
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (5);
                    ');
                }
                if (strnatcmp($version, '6') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD filter TEXT;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (6);
                    ');
                }
                // Jump straight from v6 to v8 due to bug in previous version of the code
                // in \daos\sqlite\Database which
                // set the database version to "7" for initial installs.
                if (strnatcmp($version, '8') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE sources ADD lastentry INT;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (8);
                    ');

                    $this->initLastEntryFieldDuringUpgrade();
                }
                if (strnatcmp($version, '9') < 0) {
                    \F3::get('db')->exec('
                        ALTER TABLE ' . \F3::get('db_prefix') . 'items ADD shared BOOL;
                    ');
                    \F3::get('db')->exec('
                        INSERT INTO version (version) VALUES (9);
                    ');
                }
                if (strnatcmp($version, '11') < 0) {
                    \F3::get('db')->exec([
                        // Table needs to be re-created because ALTER TABLE is rather limited.
                        // https://sqlite.org/lang_altertable.html#otheralter
                        'CREATE TABLE new_items (
                            id          INTEGER PRIMARY KEY AUTOINCREMENT,
                            datetime    DATETIME NOT NULL,
                            title       TEXT NOT NULL,
                            content     TEXT NOT NULL,
                            thumbnail   TEXT,
                            icon        TEXT,
                            unread      BOOL NOT NULL,
                            starred     BOOL NOT NULL,
                            source      INT NOT NULL,
                            uid         VARCHAR(255) NOT NULL,
                            link        TEXT NOT NULL,
                            updatetime  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            author      VARCHAR(255),
                            shared      BOOL,
                            lastseen    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        )',
                        'UPDATE items SET updatetime = datetime WHERE updatetime IS NULL',
                        'INSERT INTO new_items SELECT *, CURRENT_TIMESTAMP FROM items',
                        'DROP TABLE items',
                        'ALTER TABLE new_items RENAME TO items',
                        'CREATE INDEX source ON items (source)',
                        'CREATE TRIGGER update_updatetime_trigger
                            AFTER UPDATE ON items FOR EACH ROW
                                WHEN (
                                    OLD.unread <> NEW.unread OR
                                    OLD.starred <> NEW.starred
                                )
                                BEGIN
                                    UPDATE items
                                    SET updatetime = CURRENT_TIMESTAMP
                                    WHERE id = NEW.id;
                                END',
                        'INSERT INTO version (version) VALUES (11)'
                    ]);
                }
            }

            // just initialize once
            self::$initialized = true;
        }
    }

    /**
     * optimize database by database own optimize statement
     *
     * @return  void
     */
    public function optimize() {
        @\F3::get('db')->exec('
            VACUUM;
        ');
    }

    /**
     * Initialize 'lastentry' Field in Source table during database upgrade
     *
     * @return void
     */
    private function initLastEntryFieldDuringUpgrade() {
        $sources = @\F3::get('db')->exec('SELECT id FROM sources');

        // have a look at each entry in the source table
        foreach ($sources as $current_src) {
            // get the date of the newest entry found in the database
            $latestEntryDate = @\F3::get('db')->exec(
                'SELECT datetime FROM items WHERE source=? ORDER BY datetime DESC LIMIT 0, 1',
                $current_src['id']
            );

            // if an entry for this source was found in the database, write the date of the newest one into the sources table
            if (isset($latestEntryDate[0]['datetime'])) {
                @\F3::get('db')->exec(
                    'UPDATE sources SET lastentry=? WHERE id=?',
                    strtotime($latestEntryDate[0]['datetime']),
                    $current_src['id']
                );
            }
        }
    }
}
