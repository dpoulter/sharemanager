-- One instrument per share, and a mapping that is rebuilt rather than added to.
--
--   mysql <database> < sql/broker_mapping.sql
--
-- After the second deploy broker_instruments held 362 matched rows for 350
-- shares. Twelve shares were matched to two instruments, one of them a
-- different company: ASC to ASOSl_EQ and to ASC_US_EQ, RKT to RBl_EQ and to
-- Rocket Companies, NWG to RBSl_EQ and to NWG_US_EQ. A holding in Rocket
-- Companies would have been valued as Reckitt, and stage 2 would not know
-- which line to send an order to.
--
-- The cause was not the matching, which picks one: it was the storing. A
-- refresh wrote the rows it had matched and left every other row alone, so
-- the rows the PREVIOUS rule had matched stayed behind as matched. The same
-- is true of the four ambiguous rows that still had no ISIN and no currency
-- and notes naming pairs that were settled a deploy ago.
--
-- So: the refresh now replaces the mapping for a broker rather than merging
-- into it, and the unique key below makes a second row for one share
-- impossible rather than merely unlikely.
--
-- broker_instruments is a cache of somebody else's list. Emptying it costs
-- one request: the next sync sees no instruments, treats the list as stale
-- and fetches it. That is why the rows go rather than being picked over.
--
-- Safe to apply twice.

delete from broker_instruments;

-- A share is matched to one instrument. Ambiguous rows have a null symbol
-- and do not collide: MySQL and MariaDB allow many nulls in a unique index,
-- which is exactly the behaviour wanted here.
alter table broker_instruments
  add unique key if not exists uq_broker_symbol (broker, symbol);

-- Which venue the ticker form says the line is on, kept so the page and the
-- next investigation can see what the matcher saw:
--
--   london   <code>l_EQ, <code>l1_EQ, <code>p_EQ, <code>I_EQ
--   foreign  <code>_US_EQ, <code>_CA_EQ, <code>d_EQ (EUR), <code>s_EQ (CHF)
--
-- A foreign line never matches a London share except by ISIN. IHG is why the
-- currency alone cannot decide it: the London line IHGl1_EQ reports USD.
alter table broker_instruments
  add column if not exists venue varchar(10) null after currency;

-- What the line a holding is in is quoted in. The London line for IHG
-- reports USD, so a holding's price is not always pounds or pence even when
-- the share is a London one, and a column that does not say which is a
-- column somebody will read as pounds.
alter table broker_positions
  add column if not exists currency varchar(8) null after price_unit;

select 'broker mapping' thing,
       (select count(*) from information_schema.statistics
         where table_schema=database() and table_name='broker_instruments'
           and index_name='uq_broker_symbol') unique_key,
       (select count(*) from broker_instruments) rows_left;
