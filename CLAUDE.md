# Budget Planner Vue - Project Documentation for Claude

## Project Overview
This is a budget planning application built with:
- **Backend**: Laravel (PHP framework)
- **Frontend**: Vue 3 with Inertia.js
- **Styling**: Tailwind CSS
- **Database**: MySQL via Laravel Eloquent ORM
- **Authentication**: Laravel Sanctum/Fortify
- **Tools**: Laravel Boost MCP for interfacing with Laravel application tied to documentation

## Project Structure
```
├── app/                    # Laravel application code
│   ├── Http/Controllers/   # API and web controllers
│   ├── Models/            # Eloquent models
│   └── Services/          # Business logic services (e.g., PlaidService)
├── resources/
│   ├── js/                # Vue components and JavaScript
│   │   ├── Components/    # Reusable Vue components
│   │   └── Pages/        # Inertia page components
│   └── css/              # Tailwind CSS files
├── routes/               # Laravel routes
├── database/            # Migrations and seeders
└── tests/              # PHPUnit and feature tests
```

## Key Features
- Plaid integration for bank account connections
- Budget creation and management
- Account type categorization with drag-and-drop
- User preferences persistence
- Multi-account connection architecture

## Development Environment

This project uses **Laravel Herd** for local development:
- **Local URL**: `http://budget_planner_vue.test`
- **No need to run `php artisan serve`** - Herd handles the web server automatically
- **Vite dev server is assumed to be running** for hot module replacement and instant CSS/JS updates

## Development Commands
```bash
# Clear Laravel caches (when needed)
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Laravel Boost commands
php artisan boost:cache      # Cache routes, config, and views for performance
php artisan boost:clear      # Clear all boost caches
php artisan boost:optimize   # Run full optimization suite

# Run migrations
php artisan migrate

# Run tests
php artisan test

# Linting and type checking
npm run lint
npm run typecheck
```

## Laravel Best Practices

### Controllers
- Keep controllers thin, move business logic to services
- Use resource controllers when appropriate
- Return proper HTTP status codes
- Use form requests for validation

### Models
- Use Eloquent relationships properly
- Implement accessors and mutators for data transformation
- Use scopes for reusable query logic
- Always define fillable or guarded properties

### Services
- Create service classes for complex business logic
- Keep services focused on a single responsibility
- Inject dependencies through constructor
- Return consistent data structures

## Vue 3 & Inertia Best Practices

### Component Structure
- Use Composition API with `<script setup>` syntax
- Keep components small and focused
- Extract reusable logic into composables
- Use props validation with TypeScript when possible

### Inertia.js Guidelines
- Use Inertia forms for form submissions: `useForm()`
- Handle server-side validation errors properly
- Use partial reloads to optimize performance
- Preserve scroll position when appropriate

### State Management
- Use reactive/ref for local component state
- Consider Pinia for global state if needed
- Leverage Inertia's shared data for user/auth data

## Tailwind CSS Best Practices

### Styling Guidelines
- Use utility classes directly in templates
- Extract repeated patterns into Vue components (not CSS classes)
- Use consistent spacing scale (p-4, mt-6, etc.)
- Leverage Tailwind's responsive modifiers (sm:, md:, lg:)

### Common Patterns
- Card components: `bg-white rounded-lg shadow p-6`
- Buttons: `bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded`
- Form inputs: `border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500`

## Playwright Testing Instructions

### Setup
```bash
# Install Playwright
npm install --save-dev @playwright/test

# Install browsers
npx playwright install
```

### Writing Tests
When using Playwright for testing this application:

1. **Always verify the page loads correctly**:
   ```javascript
o   await page.goto('http://budget_planner_vue.test');
   await page.waitForLoadState('networkidle');
   ```

2. **Check for console errors**:
   ```javascript
   page.on('console', msg => {
     if (msg.type() === 'error') {
       console.error(`Console error: ${msg.text()}`);
     }
   });
   ```

3. **Wait for Inertia navigation**:
   ```javascript
   // After clicking links that trigger Inertia navigation
   await page.waitForLoadState('networkidle');
   ```

4. **Test Plaid integration**:
   - Mock Plaid responses in test environment
   - Use sandbox credentials for integration tests
   - Verify account connections and data synchronization

5. **Test drag-and-drop functionality**:
   ```javascript
   await page.dragAndDrop('#source-element', '#target-element');
   ```

### Verification Checklist
Before considering a Playwright test complete:
- ✅ Page loads without errors
- ✅ No console errors during interactions
- ✅ All API calls return expected status codes
- ✅ UI updates reflect data changes
- ✅ Forms validate and submit correctly
- ✅ Navigation works as expected

### Common Test Scenarios
1. User authentication flow
2. Plaid account connection
3. Budget creation and editing
4. Account type reorganization
5. User preferences persistence

## API Endpoints
Key API routes to be aware of:
- `/api/user` - User profile and preferences
- `/api/budgets` - Budget CRUD operations
- `/api/plaid/*` - Plaid integration endpoints
- `/api/accounts` - Bank account management

## Database Considerations
- Always use migrations for schema changes
- Create proper indexes for frequently queried columns
- Use database transactions for multi-step operations
- Implement soft deletes where appropriate

## Security Notes
- Never commit `.env` files or credentials
- Use Laravel's CSRF protection
- Validate and sanitize all user inputs
- Use prepared statements (Eloquent handles this)
- Implement proper authentication and authorization

## Performance Optimization

### Laravel Boost Integration
This project uses Laravel Boost for enhanced performance:
- **Route Caching**: Boost caches compiled routes for faster resolution
- **Config Caching**: Configuration files are cached in production
- **View Caching**: Blade templates are pre-compiled and cached
- **Query Optimization**: Automatic query optimization and caching
- **Asset Optimization**: Enhanced asset caching and compression

### General Performance Tips
- Use eager loading to prevent N+1 queries
- Implement caching for frequently accessed data
- Optimize images and assets
- Use Vite for efficient asset bundling
- Consider queue jobs for heavy operations
- Leverage Laravel Boost's automatic optimizations
- Use `php artisan boost:optimize` before deployment

## Debugging Tips
- Check Laravel logs: `storage/logs/laravel.log`
- Use `dd()` for quick debugging in PHP
- Use Vue DevTools for frontend debugging
- Monitor network tab for API responses
- Check browser console for JavaScript errors

## Recent Updates
- Implemented connection-first budget creation workflow
- Fixed Plaid discovery checkbox synchronization
- Refactored Plaid service for multi-account connections
- Added user preferences API routes
- Implemented draggable account type sections

## Claude Code Best Practices

This project follows [Claude Code best practices](https://www.anthropic.com/engineering/claude-code-best-practices):

### Workflow Approach
1. **Explore, Plan, Code**:
   - Read relevant files before making changes
   - Use "think" keyword to trigger extended planning mode
   - Plan implementation before coding
   - Verify solution reasonableness during development

2. **Test-Driven Development**:
   - Write tests first when implementing new features
   - Confirm tests initially fail
   - Implement code to pass tests
   - Verify implementation isn't overfitting to tests

### Development Guidelines
- Be specific in instructions and requirements
- Use visual references (screenshots, diagrams) when helpful
- Provide clear input/output expectations
- Course correct early and often during implementation
- Use `/clear` command to maintain focused context

### Advanced Techniques
- Leverage multiple Claude instances for code verification
- Use git worktrees for parallel development tasks
- Create custom slash commands for repeated workflows
- Utilize headless mode for automation tasks

### Security Considerations
- Carefully manage tool permissions and access
- Use containers when using `--dangerously-skip-permissions`
- Install necessary CLIs (like `gh`) properly
- Review code changes before committing
- Be cautious with file system operations
- Validate all external inputs and API responses

## Notes for Claude
- When making changes, always run linting and type checking
- Test UI changes with Playwright to ensure no console errors
- Follow existing code patterns and conventions
- Keep components modular and reusable
- Ensure proper error handling for all API calls
- Verify Inertia page props are properly typed
- Use Laravel's built-in helpers and facades appropriately
- Always read relevant files before making changes (explore first)
- Plan implementation strategy before coding
- Use Laravel Boost optimization commands appropriately

## Critical UI Verification Rule
**MANDATORY**: When adjusting any process or page in the UI, you MUST use Playwright MCP to verify the adjustment was successful by:
1. Taking a screenshot or snapshot to visually confirm the changes
2. Checking for any console errors or runtime issues
3. Verifying that the intended functionality actually works as expected
4. Confirming that no existing functionality was broken

**Example**: If you modify account display logic, you must verify that accounts are actually visible in the UI, not just that the code compiles without errors.
