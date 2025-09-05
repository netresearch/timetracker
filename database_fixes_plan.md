# Database Compatibility Fixes Plan

## MySQL→SQLite Compatibility Issues Found:

### EntryRepository.php:
1. **Line 210**: `DATE_FORMAT(e.day, '%d/%m/%Y')` → SQLite: `strftime('%d/%m/%Y', e.day)`
2. **Line 211**: `DATE_FORMAT(e.{field},'%H:%i')` → SQLite: `strftime('%H:%M', e.{field})`
3. **Line 377**: `CONCAT(p.name)` → SQLite: `p.name || ''` or just `p.name`
4. **Line 391**: `CONCAT(a.name)` → SQLite: `a.name || ''` or just `a.name`
5. **Line 495**: `YEAR(day) = :year` → SQLite: `strftime('%Y', day) = :year`
6. **Line 503-504**: `YEAR(day)` and `MONTH(day)` → SQLite equivalents

### OptimizedEntryRepository.php:
1. **Line 98**: `YEAR(e.day) = :year` → SQLite: `strftime('%Y', e.day) = :year`
2. **Line 104**: `MONTH(e.day) = :month` → SQLite: `strftime('%m', e.day) = :month`
3. **Line 173**: `CONCAT(p.name, ' (Est: ', p.estimation, ')')` → SQLite: `p.name || ' (Est: ' || p.estimation || ')'`
4. **Line 342-343**: Same YEAR/MONTH issues

## Fix Strategy:
1. Create database-agnostic helper methods in repositories
2. Use Doctrine's platform detection to choose appropriate SQL
3. Apply systematic search-and-replace for common patterns
4. Test with SQLite to ensure compatibility

## Implementation Priority:
1. ✅ Identified all MySQL-specific functions
2. 🔄 Create platform-aware SQL generation methods
3. 🔄 Update EntryRepository with compatible SQL
4. 🔄 Update OptimizedEntryRepository with compatible SQL
5. 🔄 Test repository methods work with SQLite
6. 🔄 Update test data expectations if needed