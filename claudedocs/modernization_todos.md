# PHP 8.4 Modernization Progress Tracker

## Phase 1: Core Type Safety with Enums ✅ CURRENT FOCUS
- [ ] 1.1 Create UserType enum (ADMIN, PL, DEV, USER)
- [ ] 1.2 Update User entity to use UserType enum
- [ ] 1.3 Update BaseController user type methods
- [ ] 1.4 Create EntryClass enum (PLAIN, DAYBREAK, PAUSE, OVERLAP)
- [ ] 1.5 Update Entry entity to use EntryClass enum
- [ ] 1.6 Create BillingType enum (NONE, TM, FP, MIXED)
- [ ] 1.7 Update Project entity to use BillingType enum
- [ ] 1.8 Create TicketSystemType enum (JIRA, OTRS)
- [ ] 1.9 Update TicketSystem entity
- [ ] 1.10 Create Period enum (DAY, WEEK, MONTH)
- [ ] 1.11 Update repository classes

## Phase 2: Constructor Property Promotion
- [ ] 2.1 Update service classes with constructor promotion
- [ ] 2.2 Update entity constructors
- [ ] 2.3 Update controller constructors

## Phase 3: Readonly Conversions  
- [ ] 3.1 Identify immutable value objects
- [ ] 3.2 Convert to readonly classes/properties
- [ ] 3.3 Update related code

## Phase 4: Enhanced Type Declarations
- [ ] 4.1 Add union types for flexible APIs
- [ ] 4.2 Improve nullable handling
- [ ] 4.3 Add generic type improvements

## Phase 5: Modern Patterns
- [ ] 5.1 Convert to match expressions
- [ ] 5.2 Use named arguments
- [ ] 5.3 Apply array unpacking improvements

## Validation Steps (run after each phase)
- [ ] Run `composer test` (unit tests)
- [ ] Run `composer check-all` (quality checks)
- [ ] Run `composer analyze` (PHPStan analysis)
- [ ] Manual smoke testing