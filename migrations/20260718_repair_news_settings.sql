START TRANSACTION;

SET @repair_news_settings = EXISTS(
    SELECT 1
    FROM Cfg
    WHERE Module = 'review/admin/setup'
      AND Prop IN (
          'SocialTelegram',
          'SocialX',
          'SocialVK',
          'SocialFacebook',
          'SocialInstagram',
          'SocialYouTube'
      )
);

DELETE FROM Cfg
WHERE Module = 'News'
  AND @repair_news_settings = 1;

UPDATE Cfg
SET Module = 'News'
WHERE Module = 'review/admin/setup'
  AND @repair_news_settings = 1;

COMMIT;
