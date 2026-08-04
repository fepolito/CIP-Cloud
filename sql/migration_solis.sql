-- @arquivo       sql/migration_solis.sql
-- @versao        1.0.0
-- @modificado_em 2026-08-04
-- @objetivo      Cria inversores + telemetria segregada (inversor/string) e
--                popula 32 inversores Solis agrupados em 20 estacoes (empresa_id=1).
-- @autor         Fernando / CIP Cloud Copilot / ATGY

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS inversores (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  controlador_id INT NULL,
  empresa_id     INT UNSIGNED NOT NULL,
  solis_sn       VARCHAR(32) NOT NULL,
  solis_inverter_id VARCHAR(32) NULL,
  collector_sn   VARCHAR(32) NULL,
  station_name   VARCHAR(120) NULL,
  station_id     VARCHAR(32) NULL,
  modelo         VARCHAR(60) NULL,
  potencia_nominal_w DECIMAL(10,2) NULL,
  num_mppt       TINYINT UNSIGNED NULL,
  timezone       VARCHAR(40) NOT NULL DEFAULT 'America/Sao_Paulo',
  ativo          TINYINT(1) NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_solis_sn (solis_sn),
  KEY idx_controlador (controlador_id),
  KEY idx_empresa (empresa_id),
  KEY idx_station (station_id),
  CONSTRAINT fk_inv_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id),
  CONSTRAINT fk_inv_ctrl FOREIGN KEY (controlador_id) REFERENCES controladores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telemetria_5min_inversor (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inversor_id   INT UNSIGNED NOT NULL,
  timestamp_utc DATETIME NOT NULL,
  potencia_ac_w      DECIMAL(10,2) NULL,
  energia_hoje_kwh   DECIMAL(10,3) NULL,
  energia_total_kwh  DECIMAL(14,3) NULL,
  tensao_fase_a_v    DECIMAL(6,2) NULL,
  tensao_fase_b_v    DECIMAL(6,2) NULL,
  tensao_fase_c_v    DECIMAL(6,2) NULL,
  corrente_fase_a_a  DECIMAL(8,3) NULL,
  corrente_fase_b_a  DECIMAL(8,3) NULL,
  corrente_fase_c_a  DECIMAL(8,3) NULL,
  frequencia_hz      DECIMAL(5,2) NULL,
  temperatura_c      DECIMAL(5,2) NULL,
  estado             TINYINT NULL,
  fonte         ENUM('esp','solis') NOT NULL DEFAULT 'solis',
  criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inv_bucket (inversor_id, timestamp_utc),
  KEY idx_bucket (timestamp_utc),
  CONSTRAINT fk_tel_inv FOREIGN KEY (inversor_id) REFERENCES inversores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telemetria_5min_string (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inversor_id   INT UNSIGNED NOT NULL,
  timestamp_utc DATETIME NOT NULL,
  string_num    TINYINT UNSIGNED NOT NULL,
  potencia_w    DECIMAL(10,2) NULL,
  tensao_v      DECIMAL(6,2) NULL,
  corrente_a    DECIMAL(8,3) NULL,
  fonte         ENUM('esp','solis') NOT NULL DEFAULT 'solis',
  UNIQUE KEY uk_inv_str_bucket (inversor_id, string_num, timestamp_utc),
  KEY idx_bucket (timestamp_utc),
  CONSTRAINT fk_str_inv FOREIGN KEY (inversor_id) REFERENCES inversores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed 32 inversores (empresa_id=1). ON DUPLICATE evita re-seed.
INSERT INTO inversores
 (empresa_id, solis_sn, solis_inverter_id, collector_sn, station_name, station_id, modelo, potencia_nominal_w, num_mppt, ativo)
VALUES
 (1,'100311A255180866','1308675217950361349','5A1255090BF0C166','Rodrigo César Toledo','1298491919450727657','S6-GR1P7.5K2',7500,1,1),
 (1,'100311A255070076','1308675217950359575','5A12550904A02225','Rodrigo César Toledo','1298491919450727657','S6-GR1P7.5K2',7500,1,1),
 (1,'1801150244070017','1308675217948989521','5A1243160420AFCF','Luiz C Mendes','1298491919449672612','S6-GR1P5K-S',5000,1,1),
 (1,'1804030243140143','1308675217948887941','5A12430606F04E37','Felipe Alencar','1298491919448959544','S5-GR1P10K',10000,2,1),
 (1,'1018FB023A150011','1308675217948367074','5A123921FF400EDC','Magni América do Sul','1298491919449617735','Solis-50K-LV-40A-5G-PRO',50000,7,1),
 (1,'100203021C08005','1308675217947861260','4074662813','Casa Gabi','1298491919448959547',NULL,NULL,NULL,0),
 (1,'180105021C280005','1308675217947634258','4088391325','UFV Guilherme Succi','1298491919448959549','S6-GR1P3K-M',3000,0,1),
 (1,'180105021C280785','1308675217947338398','4081923753','Ingo Thaler','1298491919448959548','S6-GR1P3K-M',3000,0,0),
 (1,'180205021C290513','1308675217947141861','4088747137','Reinert','1298491919449207146','S6-GR1P5K',5000,1,1),
 (1,'180203021C291952','1308675217947140976','4100791808','Felipe Alencar','1298491919448959544','S6-GR1P4K',4000,1,1),
 (1,'100105021C220234','1308675217947105665','4088689713','Barney','1298491919448959560','S6-GR1P3K-M',3000,0,1),
 (1,'180203021C291503','1308675217947101410','4095179531','Jorge','1298491919448959559','S6-GR1P4K',4000,1,1),
 (1,'110AE121C290707','1308675217947101381','4100101039','Renato Valbert','1298491919448959557','Solis-1P7K-5G',7000,1,1),
 (1,'110AE121C290228','1308675217947101110','4081524898','Polito','1298491919448959558','Solis-1P7K-5G',7000,1,1),
 (1,'100206021B170154','1308675217947062416','4095557761','UFV PAULO STORTI','1298491919448959552','S6-GR1P6K',6000,1,1),
 (1,'100206021B170132','1308675217947062357','4102407535','UFV PAULO STORTI','1298491919448959552','S6-GR1P6K',6000,1,1),
 (1,'100205021C150106','1308675217947062101','4090096389','Nelson Scarpato','1298491919448959555','S6-GR1P5K',5000,1,1),
 (1,'100203021C080053','1308675217947040349','4074662813','Casa Gabi','1298491919448959547','S6-GR1P4K',4000,1,1),
 (1,'100105021C060048','1308675217947039727','4087285166','Barney','1298491919448959560','S6-GR1P3K-M',3000,0,1),
 (1,'110D0221B270006','1308675217947029039','5A122428DC8027DB','Av. Rio de janeiro 581 casa 1','1298491919448959550','Solis-25K-5G',25000,2,1)
ON DUPLICATE KEY UPDATE
  solis_inverter_id=VALUES(solis_inverter_id), station_name=VALUES(station_name),
  modelo=VALUES(modelo), potencia_nominal_w=VALUES(potencia_nominal_w), num_mppt=VALUES(num_mppt);

COMMIT;
