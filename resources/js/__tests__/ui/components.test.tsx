import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { BrowserRouter } from 'react-router-dom';

// استيراد المكونات للتحقق من عدم وجود undefined
import {
  Login,
  Dashboard,
  ProjectsList,
  ProjectView,
  ProjectForm,
  TasksList,
  TaskForm,
  UsersList,
  UserView,
  UserForm,
  DepartmentsList,
  IncidentsList,
  DesignSystem,
} from '@pages';

import {
  Button,
  Input,
  Card,
  Modal,
  Table,
  Badge,
} from '@shared/ui';

import { AppLayout } from '@widgets/app-shell';
import ErrorBoundary from '@shared/ui/ErrorBoundary';

// Mock API
vi.mock('@entities/hr', () => ({
  departmentsApi: { getAll: vi.fn(() => Promise.resolve({ data: [] })), getList: vi.fn(() => Promise.resolve([])) },
}));
vi.mock('@entities/incident', () => ({
  incidentsApi: { getAll: vi.fn(() => Promise.resolve({ data: [] })) },
  incidentCategoriesApi: { getList: vi.fn(() => Promise.resolve([])) },
}));
vi.mock('@entities/project', () => ({
  projectsApi: { getAll: vi.fn(() => Promise.resolve({ data: [] })) },
}));
vi.mock('@entities/task', () => ({
  tasksApi: { getAll: vi.fn(() => Promise.resolve({ data: [] })) },
}));
vi.mock('@entities/user', () => ({
  usersApi: { getAll: vi.fn(() => Promise.resolve({ data: [] })) },
}));
vi.mock('@shared/api/auth', () => ({
  authApi: {
    getUser: vi.fn(() => Promise.resolve({
      user: {
        id: 1,
        name: 'Test User',
        email: 'test@test.com',
        roles: ['admin'],
        permissions: [],
        organization: null,
        current_organization: null,
      },
    })),
    login: vi.fn(),
    logout: vi.fn(),
  },
}));
vi.mock('@shared/api/client', () => ({
  api: {
    getToken: vi.fn(() => 'mock-token'),
    setToken: vi.fn(),
  },
}));

// Mock useAuth
vi.mock('@shared/contexts/AuthContext', () => ({
  AuthProvider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  useAuth: () => ({
    canAccess: () => true,
    user: { id: 1, name: 'Test', email: 'test@test.com', roles: ['admin'], permissions: [] },
    isLoading: false,
    isAuthenticated: true,
    login: vi.fn(),
    logout: vi.fn(),
    refreshUser: vi.fn(),
    hasRole: vi.fn(() => true),
    hasPermission: vi.fn(() => true),
    isSuperAdmin: vi.fn(() => true),
  }),
}));

// Wrapper لتوفير Router
const TestWrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <BrowserRouter>{children}</BrowserRouter>
);
// تصدير للاستخدام المستقبلي
export { TestWrapper };

describe('Component Existence Tests - منع React Error #130', () => {
  describe('Page Components', () => {
    it('Login component should be defined and be a valid React component', () => {
      expect(Login).toBeDefined();
      expect(typeof Login === 'function' || typeof Login === 'object').toBe(true);
    });

    it('Dashboard component should be defined', () => {
      expect(Dashboard).toBeDefined();
      expect(typeof Dashboard === 'function' || typeof Dashboard === 'object').toBe(true);
    });

    it('ProjectsList component should be defined', () => {
      expect(ProjectsList).toBeDefined();
      expect(typeof ProjectsList === 'function' || typeof ProjectsList === 'object').toBe(true);
    });

    it('ProjectView component should be defined', () => {
      expect(ProjectView).toBeDefined();
      expect(typeof ProjectView === 'function' || typeof ProjectView === 'object').toBe(true);
    });

    it('ProjectForm component should be defined', () => {
      expect(ProjectForm).toBeDefined();
      expect(typeof ProjectForm === 'function' || typeof ProjectForm === 'object').toBe(true);
    });

    it('TasksList component should be defined', () => {
      expect(TasksList).toBeDefined();
      expect(typeof TasksList === 'function' || typeof TasksList === 'object').toBe(true);
    });

    it('TaskForm component should be defined', () => {
      expect(TaskForm).toBeDefined();
      expect(typeof TaskForm === 'function' || typeof TaskForm === 'object').toBe(true);
    });

    it('UsersList component should be defined', () => {
      expect(UsersList).toBeDefined();
      expect(typeof UsersList === 'function' || typeof UsersList === 'object').toBe(true);
    });

    it('UserView component should be defined', () => {
      expect(UserView).toBeDefined();
      expect(typeof UserView === 'function' || typeof UserView === 'object').toBe(true);
    });

    it('UserForm component should be defined', () => {
      expect(UserForm).toBeDefined();
      expect(typeof UserForm === 'function' || typeof UserForm === 'object').toBe(true);
    });

    it('DepartmentsList component should be defined', () => {
      expect(DepartmentsList).toBeDefined();
      expect(typeof DepartmentsList === 'function' || typeof DepartmentsList === 'object').toBe(true);
    });

    it('IncidentsList component should be defined', () => {
      expect(IncidentsList).toBeDefined();
      expect(typeof IncidentsList === 'function' || typeof IncidentsList === 'object').toBe(true);
    });

    it('DesignSystem component should be defined', () => {
      expect(DesignSystem).toBeDefined();
      expect(typeof DesignSystem === 'function' || typeof DesignSystem === 'object').toBe(true);
    });
  });

  describe('UI Components', () => {
    it('Button component should be defined', () => {
      expect(Button).toBeDefined();
      expect(typeof Button === 'function' || typeof Button === 'object').toBe(true);
    });

    it('Input component should be defined', () => {
      expect(Input).toBeDefined();
      expect(typeof Input === 'function' || typeof Input === 'object').toBe(true);
    });

    it('Card component should be defined', () => {
      expect(Card).toBeDefined();
      expect(typeof Card === 'function' || typeof Card === 'object').toBe(true);
    });

    it('Modal component should be defined', () => {
      expect(Modal).toBeDefined();
      expect(typeof Modal === 'function' || typeof Modal === 'object').toBe(true);
    });

    it('Table component should be defined', () => {
      expect(Table).toBeDefined();
      expect(typeof Table === 'function' || typeof Table === 'object').toBe(true);
    });

    it('Badge component should be defined', () => {
      expect(Badge).toBeDefined();
      expect(typeof Badge === 'function' || typeof Badge === 'object').toBe(true);
    });
  });

  describe('Layout Components', () => {
    it('AppLayout component should be defined', () => {
      expect(AppLayout).toBeDefined();
      expect(typeof AppLayout === 'function' || typeof AppLayout === 'object').toBe(true);
    });
  });

  describe('Error Handling Components', () => {
    it('ErrorBoundary component should be defined', () => {
      expect(ErrorBoundary).toBeDefined();
      expect(typeof ErrorBoundary === 'function' || typeof ErrorBoundary === 'object').toBe(true);
    });
  });
});

describe('Render Tests', () => {
  it('Button should render without crashing', () => {
    render(<Button>Test Button</Button>);
    expect(screen.getByText('Test Button')).toBeInTheDocument();
  });

  it('Input should render without crashing', () => {
    render(<Input placeholder="Test input" />);
    expect(screen.getByPlaceholderText('Test input')).toBeInTheDocument();
  });

  it('Card should render without crashing', () => {
    render(<Card>Test Card Content</Card>);
    expect(screen.getByText('Test Card Content')).toBeInTheDocument();
  });

  it('Badge should render without crashing', () => {
    render(<Badge>Test Badge</Badge>);
    expect(screen.getByText('Test Badge')).toBeInTheDocument();
  });

  it('ErrorBoundary should catch errors and display fallback', () => {
    const ThrowError = () => {
      throw new Error('Test error');
    };

    // Suppress console.error for this test
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const groupSpy = vi.spyOn(console, 'group').mockImplementation(() => {});
    const groupEndSpy = vi.spyOn(console, 'groupEnd').mockImplementation(() => {});

    render(
      <ErrorBoundary>
        <ThrowError />
      </ErrorBoundary>
    );

    expect(screen.getByText(/خطأ غير متوقع/)).toBeInTheDocument();

    consoleSpy.mockRestore();
    groupSpy.mockRestore();
    groupEndSpy.mockRestore();
  });
});

describe('No Undefined Components Test', () => {
  it('should not have any undefined components in page exports', async () => {
    const pageModule = await import('@pages');

    Object.entries(pageModule).forEach(([_name, component]) => {
      expect(component).toBeDefined();
      expect(
        typeof component === 'function' || typeof component === 'object'
      ).toBe(true);
    });
  });

  it('should not have any undefined components in UI exports', async () => {
    const uiModule = await import('@shared/ui');

    Object.entries(uiModule).forEach(([_name, component]) => {
      // تخطي الـ types
      if (typeof _name === 'string' && (_name.startsWith('type') || _name.endsWith('Props'))) return;

      expect(component).toBeDefined();
    });
  });
});
