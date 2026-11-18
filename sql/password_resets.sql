-- Password reset tokens.
--
-- The reset link used to carry md5(90*13+id), which is md5(1170 + user_id):
-- computable for any account by anyone, and reset_passwd.php is exempt from the
-- login gate, so it was a pre-authentication account takeover. Tokens are now
-- random, single use, expiring, and only their hash is stored - a leaked
-- database backup does not hand over live reset links.
--
--   mysql <database> < sql/password_resets.sql

create table if not exists password_resets (
  id         int auto_increment primary key,
  user_id    int not null,
  -- sha256 of the token that went out in the email, never the token itself.
  token_hash char(64) not null,
  expires_at datetime not null,
  used_at    datetime default null,
  created_at datetime not null default current_timestamp,
  unique key (token_hash),
  key (user_id)
);
