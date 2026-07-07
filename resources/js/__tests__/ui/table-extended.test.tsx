import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';

// Mock cn utility
vi.mock('@shared/lib/utils', () => ({
  cn: (...classes: any[]) => classes.filter(Boolean).join(' '),
}));

// Mock lucide-react
vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


  IconChevronUp: () => <span data-testid="chevron-up">↑</span>,
  IconChevronDown: () => <span data-testid="chevron-down">↓</span>,
  IconSelector: () => <span data-testid="chevrons-updown">↕</span>,

  };
});

import {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableRow,
  TableHead,
  TableCell,
  TableCaption,
} from '@shared/ui/Table';

describe('Table Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table', () => {
    render(
      <Table>
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByRole('table')).toBeInTheDocument();
  });

  it('applies striped prop', () => {
    render(
      <Table striped>
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    const table = screen.getByRole('table');
    // Component applies odd row styling instead of "striped" class
    expect(table.className).toContain('nth-child');
  });

  it('applies hoverable prop', () => {
    render(
      <Table hoverable>
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    const table = screen.getByRole('table');
    expect(table.className).toContain('hover');
  });

  it('applies custom className', () => {
    render(
      <Table className="custom-table">
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    const table = screen.getByRole('table');
    expect(table.className).toContain('custom-table');
  });
});

describe('TableHeader Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table header', () => {
    render(
      <Table>
        <TableHeader data-testid="header">
          <TableRow>
            <TableHead>Header</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByTestId('header')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableHeader className="custom-header" data-testid="header">
          <TableRow>
            <TableHead>Header</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByTestId('header').className).toContain('custom-header');
  });
});

describe('TableBody Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table body', () => {
    render(
      <Table>
        <TableBody data-testid="body">
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('body')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableBody className="custom-body" data-testid="body">
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('body').className).toContain('custom-body');
  });
});

describe('TableFooter Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table footer', () => {
    render(
      <Table>
        <TableFooter data-testid="footer">
          <TableRow>
            <TableCell>Footer</TableCell>
          </TableRow>
        </TableFooter>
      </Table>
    );
    expect(screen.getByTestId('footer')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableFooter className="custom-footer" data-testid="footer">
          <TableRow>
            <TableCell>Footer</TableCell>
          </TableRow>
        </TableFooter>
      </Table>
    );
    expect(screen.getByTestId('footer').className).toContain('custom-footer');
  });
});

describe('TableRow Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table row', () => {
    render(
      <Table>
        <TableBody>
          <TableRow data-testid="row">
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('row')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableBody>
          <TableRow className="custom-row" data-testid="row">
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('row').className).toContain('custom-row');
  });
});

describe('TableHead Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table head', () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Header</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByText('Header')).toBeInTheDocument();
  });

  it('shows sort icon when sortable', () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead sortable>Sortable Header</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByTestId('chevrons-updown')).toBeInTheDocument();
  });

  it('shows ascending icon when sortDirection is asc', () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead sortable sortDirection="asc">Sorted Asc</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByTestId('chevron-up')).toBeInTheDocument();
  });

  it('shows descending icon when sortDirection is desc', () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead sortable sortDirection="desc">Sorted Desc</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    expect(screen.getByTestId('chevron-down')).toBeInTheDocument();
  });

  it('calls onSort when clicked and sortable', () => {
    const onSort = vi.fn();
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead sortable onSort={onSort}>Click to Sort</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    fireEvent.click(screen.getByText('Click to Sort'));
    expect(onSort).toHaveBeenCalledTimes(1);
  });

  it('does not call onSort when not sortable', () => {
    const onSort = vi.fn();
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead onSort={onSort}>Not Sortable</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    fireEvent.click(screen.getByText('Not Sortable'));
    expect(onSort).not.toHaveBeenCalled();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="custom-head">Header</TableHead>
          </TableRow>
        </TableHeader>
      </Table>
    );
    const th = screen.getByRole('columnheader');
    expect(th.className).toContain('custom-head');
  });
});

describe('TableCell Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table cell', () => {
    render(
      <Table>
        <TableBody>
          <TableRow>
            <TableCell>Cell Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByText('Cell Content')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableBody>
          <TableRow>
            <TableCell className="custom-cell" data-testid="cell">Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('cell').className).toContain('custom-cell');
  });
});

describe('TableCaption Component', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders table caption', () => {
    render(
      <Table>
        <TableCaption>Table Caption</TableCaption>
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByText('Table Caption')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(
      <Table>
        <TableCaption className="custom-caption" data-testid="caption">Caption</TableCaption>
        <TableBody>
          <TableRow>
            <TableCell>Content</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    );
    expect(screen.getByTestId('caption').className).toContain('custom-caption');
  });
});

describe('Table Full Example', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders full table structure', () => {
    render(
      <Table striped hoverable>
        <TableCaption>Sample Table</TableCaption>
        <TableHeader>
          <TableRow>
            <TableHead sortable sortDirection="asc">Name</TableHead>
            <TableHead sortable>Age</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow>
            <TableCell>John</TableCell>
            <TableCell>25</TableCell>
          </TableRow>
          <TableRow>
            <TableCell>Jane</TableCell>
            <TableCell>30</TableCell>
          </TableRow>
        </TableBody>
        <TableFooter>
          <TableRow>
            <TableCell colSpan={2}>Total: 2</TableCell>
          </TableRow>
        </TableFooter>
      </Table>
    );

    expect(screen.getByRole('table')).toBeInTheDocument();
    expect(screen.getByText('Sample Table')).toBeInTheDocument();
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('Age')).toBeInTheDocument();
    expect(screen.getByText('John')).toBeInTheDocument();
    expect(screen.getByText('Jane')).toBeInTheDocument();
    expect(screen.getByText('Total: 2')).toBeInTheDocument();
  });
});
