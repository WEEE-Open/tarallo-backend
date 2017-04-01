SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

DROP DATABASE `tarallo`;
CREATE DATABASE `tarallo` DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci /*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE `tarallo`;

CREATE TABLE `Feature` (
  `FeatureID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `FeatureName` text NOT NULL,
  PRIMARY KEY (`FeatureID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `Item` (
  `ItemID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Code` text COLLATE utf8mb4_unicode_ci,
  `IsDefault` tinyint(1) NOT NULL,
  -- Type and Status were removed (they will become features), to simplify implementation of the /Search thinghamajig
  PRIMARY KEY (`ItemID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `ItemFeature` (
  `FeatureID` bigint(20) unsigned NOT NULL,
  `ItemID` bigint(20) unsigned NOT NULL,
  `Value` bigint(20) DEFAULT NULL,
	`ValueText` text DEFAULT NULL,
  PRIMARY KEY (`FeatureID`,`ItemID`),
  KEY `ItemID` (`ItemID`),
  CONSTRAINT `ItemFeature_ibfk_1` FOREIGN KEY (`ItemID`) REFERENCES `Item` (`ItemID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ItemFeature_ibfk_3` FOREIGN KEY (`FeatureID`) REFERENCES `Feature` (`FeatureID`) ON DELETE NO ACTION ON UPDATE CASCADE,
	CHECK((`Value` IS NOT NULL AND `ValueText` IS NULL)
  OR (`Value` IS NULL AND `ValueText` IS NOT NULL))

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `ItemLocationModification` (
  `ModificationID` bigint(20) unsigned NOT NULL,
  `ParentFrom` bigint(20) unsigned NOT NULL,
  `ParentTo` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`ModificationID`,`ParentFrom`,`ParentTo`),
  KEY `ParentFrom` (`ParentFrom`),
  KEY `ParentTo` (`ParentTo`),
  CONSTRAINT `ItemLocationModification_ibfk_2` FOREIGN KEY (`ModificationID`) REFERENCES `Modification` (`ModificationID`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `ItemLocationModification_ibfk_3` FOREIGN KEY (`ParentFrom`) REFERENCES `Item` (`ItemID`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `ItemLocationModification_ibfk_4` FOREIGN KEY (`ParentTo`) REFERENCES `Item` (`ItemID`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `ItemModification` (
  `ModificationID` bigint(20) unsigned NOT NULL,
  `ItemID` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`ModificationID`,`ItemID`),
  KEY `ItemID` (`ItemID`),
  CONSTRAINT `ItemModification_ibfk_1` FOREIGN KEY (`ModificationID`) REFERENCES `Modification` (`ModificationID`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `ItemModification_ibfk_3` FOREIGN KEY (`ItemID`) REFERENCES `Item` (`ItemID`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `ItemStatus` (
  `StatusID` int(11) NOT NULL AUTO_INCREMENT,
  `StatusText` text NOT NULL,
  PRIMARY KEY (`StatusID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `ItemType` (
  `TypeID` int(11) NOT NULL AUTO_INCREMENT,
  `TypeText` text NOT NULL,
  PRIMARY KEY (`TypeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `Modification` (
  `ModificationID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` bigint(20) unsigned NOT NULL,
  `Date` datetime NOT NULL,
  `Notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`ModificationID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `Modification_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `User` (`UserID`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Tree` (
  `AncestorID` bigint(20) unsigned NOT NULL,
  `DescendantID` bigint(20) unsigned NOT NULL,
  `Depth` int(10) unsigned NOT NULL,
  PRIMARY KEY (`AncestorID`,`DescendantID`),
  CONSTRAINT `Tree_ibfk_1` FOREIGN KEY (`AncestorID`) REFERENCES `Item` (`ItemID`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `Tree_ibfk_2` FOREIGN KEY (`DescendantID`) REFERENCES `Item` (`ItemID`) ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `User` (
  `UserID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL, -- 190 * 4 bytes = 760, less than the apparently random limit of 767 bytes.
  `Password` text COLLATE utf8mb4_unicode_ci NOT NULL,
	`Session` char(32) COLLATE utf8mb4_unicode_ci,
	`SessionExpiry` bigint(20),
	`Enabled` tinyint(1) unsigned NOT NULL DEFAULT 0,
	CHECK((`Session` IS NOT NULL AND `SessionExpiry` IS NOT NULL)
			OR (`Session` IS NULL AND `SessionExpiry` IS NULL)),
	PRIMARY KEY (`UserID`),
	UNIQUE KEY (`Session`),
	UNIQUE KEY (`Name`),
	INDEX (`Name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

