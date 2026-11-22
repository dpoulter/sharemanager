-- The industry_map module (includes/industry_map.php): which of ICB's 11
-- industries a share belongs to, from the sector names the providers use.
--
--   mysql <database> < sql/industry_map.sql
--
-- industries     the 11 industries, in the order they are listed
-- industry_map   one row per raw name:
--                  source 'n100': sector is a stock_symbols.sector name;
--                  source 'fmp':  sector is FMP's sector, and fmp_industry is
--                                 '' for the sector's own industry or one of
--                                 FMP's industry names for an exception to it.
--
-- The map is data, reviewed by Dale (22 Nov 2026). A name is in it once, so
-- it has one industry; a name not in it is unmapped, never guessed.
-- includes/industry_map.php is the only code that writes these tables or
-- reads them to decide an industry. The map's version is worked out from
-- its rows, so any change to a row is a new version.
--
-- Safe to apply twice: a row already there is left as it is.

create table if not exists industries (
  name      varchar(50) not null primary key,
  position  int not null
);

create table if not exists industry_map (
  source        varchar(10)  not null,
  sector        varchar(150) not null,
  fmp_industry  varchar(150) not null default '',
  industry      varchar(50)  not null,
  primary key (source, sector, fmp_industry)
);

insert ignore into industries (name, position) values
  ('Technology', 1),
  ('Telecommunications', 2),
  ('Health Care', 3),
  ('Financials', 4),
  ('Real Estate', 5),
  ('Consumer Discretionary', 6),
  ('Consumer Staples', 7),
  ('Industrials', 8),
  ('Basic Materials', 9),
  ('Energy', 10),
  ('Utilities', 11);

insert ignore into industry_map (source, sector, fmp_industry, industry) values
  ('n100', 'Industrial Metals and Mining', '', 'Basic Materials'),
  ('n100', 'Chemicals', '', 'Basic Materials'),
  ('n100', 'Precious Metals and Mining', '', 'Basic Materials'),
  ('n100', 'Basic Resources', '', 'Basic Materials'),
  ('n100', 'Retailers', '', 'Consumer Discretionary'),
  ('n100', 'Travel and Leisure', '', 'Consumer Discretionary'),
  ('n100', 'Media', '', 'Consumer Discretionary'),
  ('n100', 'Travel & Leisure', '', 'Consumer Discretionary'),
  ('n100', 'Household goods & home construction', '', 'Consumer Discretionary'),
  ('n100', 'Personal Goods', '', 'Consumer Discretionary'),
  ('n100', 'Leisure Goods', '', 'Consumer Discretionary'),
  ('n100', 'Home Construction', '', 'Consumer Discretionary'),
  ('n100', 'Household Goods and Home Construction', '', 'Consumer Discretionary'),
  ('n100', 'Consumer Services', '', 'Consumer Discretionary'),
  ('n100', 'Retail', '', 'Consumer Discretionary'),
  ('n100', 'Food Producers', '', 'Consumer Staples'),
  ('n100', 'Personal Care, Drug and Grocery Stores', '', 'Consumer Staples'),
  ('n100', 'Beverages', '', 'Consumer Staples'),
  ('n100', 'Food & Drug Retailers', '', 'Consumer Staples'),
  ('n100', 'Tobacco', '', 'Consumer Staples'),
  ('n100', 'Personal Products', '', 'Consumer Staples'),
  ('n100', 'Consumer Staples', '', 'Consumer Staples'),
  ('n100', 'Food and drink', '', 'Consumer Staples'),
  ('n100', 'Food, Beverage and Tobacco', '', 'Consumer Staples'),
  ('n100', 'Non-Renewable Energy', '', 'Energy'),
  ('n100', 'Oil & Gas Producers', '', 'Energy'),
  ('n100', 'Energy', '', 'Energy'),
  ('n100', 'Alternative Energy', '', 'Energy'),
  ('n100', 'Oil, Gas and Coal', '', 'Energy'),
  ('n100', 'Investment Trusts', '', 'Financials'),
  ('n100', 'Financial Services', '', 'Financials'),
  ('n100', 'Banks', '', 'Financials'),
  ('n100', 'Investment Banking and Brokerage Services', '', 'Financials'),
  ('n100', 'Closed End Investments', '', 'Financials'),
  ('n100', 'Life Insurance', '', 'Financials'),
  ('n100', 'General Financial', '', 'Financials'),
  ('n100', 'Collective Investments', '', 'Financials'),
  ('n100', 'Equity Investments', '', 'Financials'),
  ('n100', 'Non-life Insurance', '', 'Financials'),
  ('n100', 'Hedge Funds', '', 'Financials'),
  ('n100', 'Finance and Credit Services', '', 'Financials'),
  ('n100', 'Nonlife Insurance', '', 'Financials'),
  ('n100', 'Banking', '', 'Financials'),
  ('n100', 'Pharmaceuticals, Biotechnology and Marijuana Producers', '', 'Health Care'),
  ('n100', 'Medical Equipment and Services', '', 'Health Care'),
  ('n100', 'Pharmaceuticals and Biotechnology', '', 'Health Care'),
  ('n100', 'Health Care', '', 'Health Care'),
  ('n100', 'Pharmaceuticals & Biotechnology', '', 'Health Care'),
  ('n100', 'Health Care Equipment & Services', '', 'Health Care'),
  ('n100', 'Support Services', '', 'Industrials'),
  ('n100', 'Construction & Materials', '', 'Industrials'),
  ('n100', 'Industrial Support Services', '', 'Industrials'),
  ('n100', 'Industrial Goods and Services', '', 'Industrials'),
  ('n100', 'Industrial Engineering', '', 'Industrials'),
  ('n100', 'Aerospace and Defense', '', 'Industrials'),
  ('n100', 'General Industrials', '', 'Industrials'),
  ('n100', 'Electronic & Electrical Equipment', '', 'Industrials'),
  ('n100', 'Aerospace & Defence', '', 'Industrials'),
  ('n100', 'Industrial Transportation', '', 'Industrials'),
  ('n100', 'Electronic and Electrical Equipment', '', 'Industrials'),
  ('n100', 'Construction', '', 'Industrials'),
  ('n100', 'Construction and Materials', '', 'Industrials'),
  ('n100', 'Engineering and Construction', '', 'Industrials'),
  ('n100', 'Containers & packaging', '', 'Industrials'),
  ('n100', 'Industrials', '', 'Industrials'),
  ('n100', 'Real Estate Investment Trusts', '', 'Real Estate'),
  ('n100', 'Real Estate Investment & Services', '', 'Real Estate'),
  ('n100', 'Real Estate', '', 'Real Estate'),
  ('n100', 'Software and Computer Services', '', 'Technology'),
  ('n100', 'Software & Computer Services', '', 'Technology'),
  ('n100', 'Technology', '', 'Technology'),
  ('n100', 'Consumer Digital Services', '', 'Technology'),
  ('n100', 'Telecommunications Service Providers', '', 'Telecommunications'),
  ('n100', 'Telecomms', '', 'Telecommunications'),
  ('n100', 'Telecommunications', '', 'Telecommunications'),
  ('n100', 'Gas, Water and Multi-utilities', '', 'Utilities'),
  ('n100', 'Electricity', '', 'Utilities'),
  ('n100', 'Gas, Water & Multiutilities', '', 'Utilities'),
  ('fmp', 'Basic Materials', '', 'Basic Materials'),
  ('fmp', 'Communication Services', '', 'Consumer Discretionary'),
  ('fmp', 'Consumer Cyclical', '', 'Consumer Discretionary'),
  ('fmp', 'Consumer Defensive', '', 'Consumer Staples'),
  ('fmp', 'Energy', '', 'Energy'),
  ('fmp', 'Financial Services', '', 'Financials'),
  ('fmp', 'Healthcare', '', 'Health Care'),
  ('fmp', 'Industrials', '', 'Industrials'),
  ('fmp', 'Real Estate', '', 'Real Estate'),
  ('fmp', 'Technology', '', 'Technology'),
  ('fmp', 'Utilities', '', 'Utilities'),
  ('fmp', 'Basic Materials', 'Construction Materials', 'Industrials'),
  ('fmp', 'Communication Services', 'Internet Content & Information', 'Technology'),
  ('fmp', 'Communication Services', 'Telecommunications Services', 'Telecommunications'),
  ('fmp', 'Consumer Cyclical', 'Packaging & Containers', 'Industrials'),
  ('fmp', 'Consumer Defensive', 'Education & Training Services', 'Consumer Discretionary'),
  ('fmp', 'Industrials', 'Airlines, Airports & Air Services', 'Consumer Discretionary'),
  ('fmp', 'Industrials', 'Agricultural - Commodities/Milling', 'Consumer Staples'),
  ('fmp', 'Technology', 'Consumer Electronics', 'Consumer Discretionary'),
  ('fmp', 'Technology', 'Electronic Gaming & Multimedia', 'Consumer Discretionary'),
  ('fmp', 'Technology', 'Communication Equipment', 'Telecommunications'),
  ('fmp', 'Energy', 'Uranium', 'Energy');

select source, count(*) names from industry_map group by source;
