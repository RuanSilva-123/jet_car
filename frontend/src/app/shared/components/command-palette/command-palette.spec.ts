import { toActions } from './command-palette';

describe('toActions (busca global)', () => {
  it('placa com OS em aberto leva primeiro à OS, depois ao histórico e ao cliente', () => {
    const actions = toActions({
      vehicles: [
        {
          id: 3,
          brand: 'Fiat',
          model: 'Uno',
          model_year: 2015,
          plate: 'ABC1234',
          customer: { id: 9, name: 'Ruan Silva' },
          active_order: { id: 42, number: '00042', status: 'in_progress', status_label: 'Em andamento' },
        },
      ],
      customers: [],
      orders: [{ id: 42, number: '00042', status: 'in_progress', status_label: 'Em andamento', customer: 'Ruan Silva', vehicle: 'Fiat Uno', plate: 'ABC1234' }],
    });

    expect(actions.map((action) => action.route.join('/'))).toEqual(['/service-orders/42', '/vehicles/3/history', '/customers/9/edit']);
    expect(actions[0].title).toContain('ABC-1234');
    expect(actions[0].badge).toBe('Em andamento');
  });

  it('sem resultado devolve lista vazia', () => {
    expect(toActions({ vehicles: [], customers: [], orders: [] })).toEqual([]);
  });
});
