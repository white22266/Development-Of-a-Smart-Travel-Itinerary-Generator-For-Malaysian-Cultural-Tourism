-- Open place data: places are kept up to date from OpenStreetMap, Wikidata, Wikipedia and
-- Wikimedia Commons by tools/sync_places.php instead of being typed in by an admin.
-- Safe to re-run.

-- Where a place's data came from, and the credits its text and photo need. One row per place.
CREATE TABLE IF NOT EXISTS place_sources (
    place_id            INT NOT NULL,
    origin              VARCHAR(20) NOT NULL DEFAULT 'manual',   -- manual | import
    osm_ref             VARCHAR(32) DEFAULT NULL,                -- node/123, way/456, relation/789
    wikidata_id         VARCHAR(20) DEFAULT NULL,
    wikipedia_url       VARCHAR(400) DEFAULT NULL,
    description_source  VARCHAR(20) DEFAULT NULL,                -- wikipedia | generated
    image_source_url    VARCHAR(500) DEFAULT NULL,               -- Wikimedia Commons file page
    image_credit        VARCHAR(300) DEFAULT NULL,
    image_license       VARCHAR(80) DEFAULT NULL,
    wheelchair          VARCHAR(10) DEFAULT NULL,                -- yes | limited | no
    synced_at           DATETIME DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (place_id),
    UNIQUE KEY uq_place_sources_osm (osm_ref),
    KEY idx_place_sources_wikidata (wikidata_id),
    CONSTRAINT fk_place_sources_place FOREIGN KEY (place_id) REFERENCES cultural_places (place_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Places found in open data that are not in cultural_places yet.
-- High-scoring ones are published automatically; the rest wait for an admin.
CREATE TABLE IF NOT EXISTS place_import_candidates (
    candidate_id          INT NOT NULL AUTO_INCREMENT,
    osm_ref               VARCHAR(32) NOT NULL,
    wikidata_id           VARCHAR(20) DEFAULT NULL,
    name                  VARCHAR(150) NOT NULL,
    state                 VARCHAR(60) NOT NULL,
    district              VARCHAR(80) DEFAULT NULL,
    category              ENUM('culture','heritage','museum','food','festival','nature','shopping') NOT NULL,
    latitude              DECIMAL(10,7) NOT NULL,
    longitude             DECIMAL(10,7) NOT NULL,
    score                 TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0-100, how well documented the place is
    payload_json          MEDIUMTEXT NOT NULL,                   -- everything needed to publish it
    -- pending: waiting for an admin; duplicate: probably a place the database already has;
    -- linked: an admin confirmed it is that place; published / rejected: decided
    status                ENUM('pending','published','rejected','duplicate','linked') NOT NULL DEFAULT 'pending',
    published_place_id    INT DEFAULT NULL,
    duplicate_of_place_id INT DEFAULT NULL,
    reviewed_by_admin_id  INT DEFAULT NULL,
    reviewed_at           DATETIME DEFAULT NULL,
    first_seen_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at          DATETIME NOT NULL,
    PRIMARY KEY (candidate_id),
    UNIQUE KEY uq_candidate_osm (osm_ref),
    KEY idx_candidate_status (status, state, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every value the sync changed on an existing place, so it can be reviewed or undone.
CREATE TABLE IF NOT EXISTS place_change_log (
    change_id   INT NOT NULL AUTO_INCREMENT,
    place_id    INT NOT NULL,
    field       VARCHAR(40) NOT NULL,
    old_value   TEXT DEFAULT NULL,
    new_value   TEXT DEFAULT NULL,
    source      VARCHAR(20) NOT NULL,          -- osm | wikidata | wikipedia | commons
    source_ref  VARCHAR(400) DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reverted_at DATETIME DEFAULT NULL,         -- set when an admin undid the change; the sync then leaves that field alone
    PRIMARY KEY (change_id),
    KEY idx_place_change_place (place_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS place_sync_runs (
    run_id          INT NOT NULL AUTO_INCREMENT,
    state           VARCHAR(60) NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'running',   -- running | done | failed
    started_at      DATETIME NOT NULL,
    finished_at     DATETIME DEFAULT NULL,
    osm_places      INT NOT NULL DEFAULT 0,
    linked          INT NOT NULL DEFAULT 0,
    fields_filled   INT NOT NULL DEFAULT 0,
    staged          INT NOT NULL DEFAULT 0,
    published       INT NOT NULL DEFAULT 0,
    lodgings        INT NOT NULL DEFAULT 0,
    message         VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (run_id),
    KEY idx_sync_runs_state (state, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Hotels, guest houses, hostels and resorts from OpenStreetMap. Hotel suggestions are
-- answered from this table, so no outside service is called while a traveller waits.
CREATE TABLE IF NOT EXISTS lodgings (
    lodging_id    INT NOT NULL AUTO_INCREMENT,
    osm_ref       VARCHAR(32) NOT NULL,
    name          VARCHAR(200) NOT NULL,
    kind          VARCHAR(20) NOT NULL,          -- hotel | guest_house | hostel | motel | resort | apartment | chalet
    stars         DECIMAL(2,1) DEFAULT NULL,
    state         VARCHAR(60) NOT NULL,
    address       VARCHAR(255) DEFAULT NULL,
    latitude      DECIMAL(10,7) NOT NULL,
    longitude     DECIMAL(10,7) NOT NULL,
    phone         VARCHAR(60) DEFAULT NULL,
    website       VARCHAR(300) DEFAULT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at  DATETIME NOT NULL,
    PRIMARY KEY (lodging_id),
    UNIQUE KEY uq_lodgings_osm (osm_ref),
    KEY idx_lodgings_geo (latitude, longitude),
    KEY idx_lodgings_state (state, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
